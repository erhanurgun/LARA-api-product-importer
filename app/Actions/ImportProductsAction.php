<?php

namespace App\Actions;

use App\Contracts\ApiClientInterface;
use App\Contracts\CheckpointManagerInterface;
use App\Contracts\DataMapperInterface;
use App\Contracts\RateLimiterInterface;
use App\Contracts\SerializerInterface;
use App\Contracts\ValidatorInterface;
use App\DataTransferObjects\ImportStatistics;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

final class ImportProductsAction
{
    public function __construct(
        private readonly ApiClientInterface $apiClient,
        private readonly ValidatorInterface $validator,
        private readonly RateLimiterInterface $rateLimiter,
        private readonly DataMapperInterface $dataMapper,
        private readonly CheckpointManagerInterface $checkpointManager,
        private readonly SerializerInterface $serializer,
    ) {}

    /**
     * Execute the import process.
     *
     * @param  int  $startPage  Starting page number
     * @param  bool  $dryRun  If true, validation only (no database save)
     */
    public function execute(int $startPage, bool $dryRun = false): ImportStatistics
    {
        $stats = new ImportStatistics(
            startTime: microtime(true),
            startMemory: memory_get_usage(true),
        );

        $currentPage = $startPage;
        $lastPage = null;
        $retries = 0;
        $maxRetries = (int) config('import.retry.max_attempts', 3);

        // lastPage ilk başarılı yanıta kadar null; null karşılaştırması ilk sayfa hatasında döngüyü sessizce bitiriyordu
        do {
            $this->rateLimiter->throttle();

            try {
                $response = $this->apiClient->fetchProducts($currentPage);
                $this->rateLimiter->hit();

                [$succeeded, $failed] = $this->processPage($response->data, $dryRun);
                $this->checkpointManager->save($currentPage + 1);

                // Sayımlar sayfa tamamlanınca: kayıt ya da checkpoint yeniden denenirse sayfa iki kez sayılmasın
                $this->recordPageStats($stats, $succeeded, $failed);

                // Hata alan sayfanın lastPage değeri yeniden denemeden önce döngüyü sessizce bitirmesin
                $lastPage = $response->lastPage;

                $currentPage++;
                $retries = 0;
            } catch (\Throwable $e) {
                if ($retries < $maxRetries && $this->isRecoverableError($e)) {
                    $retries++;

                    Log::warning('Recoverable error during import', [
                        'page' => $currentPage,
                        'error' => $e->getMessage(),
                        'retry' => $retries,
                    ]);
                    Sleep::for(5)->seconds();

                    continue;
                }

                throw $e;
            }
        } while ($lastPage === null || $currentPage <= $lastPage);

        return $stats;
    }

    /**
     * Process a single page of products.
     *
     * @param  array<array<string, mixed>>  $products
     * @return array{int, int} geçerli ve geçersiz ürün sayısı
     */
    private function processPage(array $products, bool $dryRun): array
    {
        $validProducts = [];
        $failed = 0;

        foreach ($products as $apiProduct) {
            $mappedData = $this->dataMapper->mapToDatabaseFormat($apiProduct);
            $result = $this->validator->validateSafe($mappedData, checkUnique: false);

            if ($result['valid']) {
                $validProducts[] = $result['data'];
            } else {
                $failed++;
            }
        }

        if (! $dryRun && count($validProducts) > 0) {
            $this->saveProducts($validProducts);
        }

        return [count($validProducts), $failed];
    }

    private function recordPageStats(ImportStatistics $stats, int $succeeded, int $failed): void
    {
        for ($i = 0; $i < $succeeded; $i++) {
            $stats->incrementSuccess();
        }
        for ($i = 0; $i < $failed; $i++) {
            $stats->incrementFailed();
        }
    }

    /**
     * Save products to database.
     *
     * @param  array<array<string, mixed>>  $products
     *
     * @throws \Throwable
     */
    private function saveProducts(array $products): void
    {
        try {
            DB::transaction(function () use ($products) {
                $serializedProducts = $this->serializer->serializeBatch($products);

                Product::upsert(
                    $serializedProducts,
                    uniqueBy: ['slug'],
                    update: [
                        'title', 'content', 'price', 'old_price', 'discount_percentage',
                        'quantity', 'in_stock', 'image_cover', 'image_thumbnail',
                        'container_type', 'container_size', 'production_year', 'condition',
                        'location_city', 'location_district', 'location_country',
                        'type', 'is_new', 'is_hot_sale', 'is_featured', 'is_bulk_sale',
                        'accept_offers', 'status', 'colors', 'all_prices',
                        'technical_specs', 'user_info',
                    ]
                );
            });

            Log::info('Products saved successfully', ['count' => count($products)]);
        } catch (\Throwable $e) {
            Log::channel('import_errors')->error('Failed to save products', [
                'error' => $e->getMessage(),
                'count' => count($products),
            ]);

            throw $e;
        }
    }

    /**
     * Check if the exception is recoverable.
     */
    private function isRecoverableError(\Throwable $exception): bool
    {
        // QueryException iletisi her zaman "Connection" içerir; kalıcı SQLSTATE 22 ve 23 hataları geçici sayılmaz
        if ($exception instanceof QueryException
            && in_array(substr((string) $exception->getCode(), 0, 2), ['22', '23'], true)) {
            return false;
        }

        $message = strtolower($exception->getMessage());
        $recoverablePatterns = config('import.recoverable_errors', []);

        foreach ($recoverablePatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
