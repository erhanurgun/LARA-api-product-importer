<?php

use App\Actions\ImportProductsAction;
use App\Contracts\ApiClientInterface;
use App\Contracts\CheckpointManagerInterface;
use App\Contracts\DataMapperInterface;
use App\Contracts\RateLimiterInterface;
use App\Contracts\SerializerInterface;
use App\Contracts\ValidatorInterface;
use App\DataTransferObjects\ApiResponse;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

it('executes import successfully', function () {
    $apiClient = mock(ApiClientInterface::class);
    $validator = mock(ValidatorInterface::class);
    $rateLimiter = mock(RateLimiterInterface::class);
    $dataMapper = mock(DataMapperInterface::class);
    $checkpointManager = mock(CheckpointManagerInterface::class);
    $serializer = mock(SerializerInterface::class);

    $apiClient->shouldReceive('fetchProducts')
        ->once()
        ->with(1)
        ->andReturn(new ApiResponse(
            data: [
                ['id' => 'uuid1', 'title' => 'Product 1'],
            ],
            currentPage: 1,
            lastPage: 1,
            total: 1
        ));

    $rateLimiter->shouldReceive('throttle')->once();
    $rateLimiter->shouldReceive('hit')->once();

    $dataMapper->shouldReceive('mapToDatabaseFormat')
        ->once()
        ->andReturn([
            'id' => 'uuid1',
            'title' => 'Product 1',
            'slug' => 'product-1',
            'price' => 100,
            'quantity' => 10,
            'in_stock' => true,
            'status' => 'published',
        ]);

    $validator->shouldReceive('validateSafe')
        ->once()
        ->andReturn([
            'valid' => true,
            'data' => ['id' => 'uuid1', 'title' => 'Product 1', 'slug' => 'product-1', 'price' => 100],
            'errors' => null,
        ]);

    // upsert slug ve price (NOT NULL) ister; önceki sahte veri bu alanları içermiyordu
    $serializer->shouldReceive('serializeBatch')
        ->once()
        ->andReturn([['id' => 'uuid1', 'title' => 'Product 1', 'slug' => 'product-1', 'price' => 100]]);

    $checkpointManager->shouldReceive('save')->once()->with(2);

    $action = new ImportProductsAction(
        $apiClient,
        $validator,
        $rateLimiter,
        $dataMapper,
        $checkpointManager,
        $serializer
    );

    $stats = $action->execute(startPage: 1, dryRun: false);

    expect($stats->totalProcessed)->toBe(1)
        ->and($stats->successfulImports)->toBe(1)
        ->and($stats->failedValidations)->toBe(0);
});

it('skips invalid products during import', function () {
    $apiClient = mock(ApiClientInterface::class);
    $validator = mock(ValidatorInterface::class);
    $rateLimiter = mock(RateLimiterInterface::class);
    $dataMapper = mock(DataMapperInterface::class);
    $checkpointManager = mock(CheckpointManagerInterface::class);
    $serializer = mock(SerializerInterface::class);

    $apiClient->shouldReceive('fetchProducts')
        ->once()
        ->andReturn(new ApiResponse(
            data: [
                ['id' => 'valid'],
                ['id' => 'invalid'],
            ],
            currentPage: 1,
            lastPage: 1,
            total: 2
        ));

    $rateLimiter->shouldReceive('throttle')->once();
    $rateLimiter->shouldReceive('hit')->once();

    $dataMapper->shouldReceive('mapToDatabaseFormat')->twice()->andReturn([]);

    $validator->shouldReceive('validateSafe')
        ->twice()
        ->andReturn(
            ['valid' => true, 'data' => ['id' => 'valid'], 'errors' => null],
            ['valid' => false, 'data' => null, 'errors' => ['error']]
        );

    $serializer->shouldReceive('serializeBatch')->once()->andReturn([]);
    $checkpointManager->shouldReceive('save')->once();

    $action = new ImportProductsAction(
        $apiClient,
        $validator,
        $rateLimiter,
        $dataMapper,
        $checkpointManager,
        $serializer
    );

    $stats = $action->execute(startPage: 1, dryRun: false);

    expect($stats->successfulImports)->toBe(1)
        ->and($stats->failedValidations)->toBe(1);
});

it('respects dry run mode', function () {
    $apiClient = mock(ApiClientInterface::class);
    $validator = mock(ValidatorInterface::class);
    $rateLimiter = mock(RateLimiterInterface::class);
    $dataMapper = mock(DataMapperInterface::class);
    $checkpointManager = mock(CheckpointManagerInterface::class);
    $serializer = mock(SerializerInterface::class);

    $apiClient->shouldReceive('fetchProducts')
        ->once()
        ->andReturn(new ApiResponse(
            data: [['id' => 'uuid1']],
            currentPage: 1,
            lastPage: 1,
            total: 1
        ));

    $rateLimiter->shouldReceive('throttle')->once();
    $rateLimiter->shouldReceive('hit')->once();
    $dataMapper->shouldReceive('mapToDatabaseFormat')->once()->andReturn([]);
    $validator->shouldReceive('validateSafe')->once()->andReturn([
        'valid' => true,
        'data' => [],
        'errors' => null,
    ]);

    $serializer->shouldReceive('serializeBatch')->never();
    $checkpointManager->shouldReceive('save')->once();

    $action = new ImportProductsAction(
        $apiClient,
        $validator,
        $rateLimiter,
        $dataMapper,
        $checkpointManager,
        $serializer
    );

    $stats = $action->execute(startPage: 1, dryRun: true);

    expect(Product::count())->toBe(0)
        ->and($stats->successfulImports)->toBe(1);
});

function makeRetryAction(ApiClientInterface $apiClient, ?CheckpointManagerInterface $checkpointManager = null): ImportProductsAction
{
    $rateLimiter = mock(RateLimiterInterface::class);
    $rateLimiter->shouldReceive('throttle');
    $rateLimiter->shouldReceive('hit');

    $dataMapper = mock(DataMapperInterface::class);
    $dataMapper->shouldReceive('mapToDatabaseFormat')->andReturn([]);

    $validator = mock(ValidatorInterface::class);
    $validator->shouldReceive('validateSafe')->andReturn(['valid' => false, 'data' => null, 'errors' => ['x']]);

    if ($checkpointManager === null) {
        $checkpointManager = mock(CheckpointManagerInterface::class);
        $checkpointManager->shouldReceive('save');
    }

    return new ImportProductsAction(
        $apiClient,
        $validator,
        $rateLimiter,
        $dataMapper,
        $checkpointManager,
        mock(SerializerInterface::class)
    );
}

it('does not retry a permanent query exception', function () {
    Sleep::fake();
    config(['import.retry.max_attempts' => 3]);

    $permanent = new QueryException(
        'sqlite',
        'insert into products ...',
        [],
        new PDOException('NOT NULL constraint failed: products.slug', 23000)
    );

    $apiClient = mock(ApiClientInterface::class);
    $apiClient->shouldReceive('fetchProducts')->once()->andThrow($permanent);

    expect(fn () => makeRetryAction($apiClient)->execute(startPage: 1))
        ->toThrow(QueryException::class);

    Sleep::assertNeverSlept();
});

it('stops retrying a transient error after max retries', function () {
    Sleep::fake();
    config(['import.retry.max_attempts' => 3]);

    $apiClient = mock(ApiClientInterface::class);
    $apiClient->shouldReceive('fetchProducts')
        ->times(4)
        ->andThrow(new RuntimeException('Connection timeout'));

    expect(fn () => makeRetryAction($apiClient)->execute(startPage: 1))
        ->toThrow(RuntimeException::class, 'Connection timeout');

    Sleep::assertSleptTimes(3);
});

it('completes the import after one transient error', function () {
    Sleep::fake();
    config(['import.retry.max_attempts' => 3]);

    $apiClient = mock(ApiClientInterface::class);
    $apiClient->shouldReceive('fetchProducts')
        ->twice()
        ->with(1)
        ->andReturnUsing(
            fn () => throw new RuntimeException('Network temporarily unavailable'),
            fn () => new ApiResponse(data: [], currentPage: 1, lastPage: 1, total: 0)
        );

    $checkpointManager = mock(CheckpointManagerInterface::class);
    $checkpointManager->shouldReceive('save')->once()->with(2);

    $stats = makeRetryAction($apiClient, $checkpointManager)->execute(startPage: 1);

    expect($stats->totalProcessed)->toBe(0);
    Sleep::assertSleptTimes(1);
});

it('counts a retried page only once', function () {
    Sleep::fake();
    config(['import.retry.max_attempts' => 3]);

    $apiClient = mock(ApiClientInterface::class);
    $apiClient->shouldReceive('fetchProducts')
        ->twice()
        ->with(1)
        ->andReturn(new ApiResponse(data: [['id' => 'uuid1']], currentPage: 1, lastPage: 1, total: 1));

    $checkpointManager = mock(CheckpointManagerInterface::class);
    $checkpointManager->shouldReceive('save')
        ->twice()
        ->with(2)
        ->andReturnUsing(
            fn () => throw new RuntimeException('Connection reset'),
            fn () => null
        );

    $stats = makeRetryAction($apiClient, $checkpointManager)->execute(startPage: 1);

    expect($stats->failedValidations)->toBe(1)
        ->and($stats->totalProcessed)->toBe(1);
});

it('retries a failed page even when its response reports a lower last page', function () {
    Sleep::fake();
    config(['import.retry.max_attempts' => 3]);

    $apiClient = mock(ApiClientInterface::class);
    $apiClient->shouldReceive('fetchProducts')
        ->twice()
        ->with(5)
        ->andReturn(new ApiResponse(data: [['id' => 'uuid1']], currentPage: 5, lastPage: 3, total: 1));

    $checkpointManager = mock(CheckpointManagerInterface::class);
    $checkpointManager->shouldReceive('save')
        ->twice()
        ->with(6)
        ->andReturnUsing(
            fn () => throw new RuntimeException('Connection reset'),
            fn () => null
        );

    $stats = makeRetryAction($apiClient, $checkpointManager)->execute(startPage: 5);

    expect($stats->failedValidations)->toBe(1);
    Sleep::assertSleptTimes(1);
});
