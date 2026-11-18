<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Vistynq\Http\Request;
use Vistynq\Support\ApiException;
use Vistynq\Support\Pagination;

/**
 * R19: разбор `page`/`perPage` и конверт `{items, page, perPage, total}`.
 * Невалидные значения — 422, page за последней страницей — корректный offset.
 */
final class PaginationTest extends TestCase
{
    public function testDefaults(): void
    {
        $pagination = Pagination::fromQuery([]);

        $this->assertSame(1, $pagination->page);
        $this->assertSame(50, $pagination->perPage);
        $this->assertSame(0, $pagination->offset());
        $this->assertSame(50, $pagination->limit());
    }

    public function testParsesPageAndPerPage(): void
    {
        $pagination = Pagination::fromQuery(['page' => '3', 'perPage' => '10']);

        $this->assertSame(3, $pagination->page);
        $this->assertSame(10, $pagination->perPage);
        $this->assertSame(20, $pagination->offset());
    }

    public function testAllowsMaximumPerPage(): void
    {
        $pagination = Pagination::fromQuery(['perPage' => '200']);

        $this->assertSame(200, $pagination->perPage);
    }

    public function testRejectsInvalidValues(): void
    {
        $cases = [
            ['page' => '0'],
            ['page' => '-1'],
            ['page' => 'abc'],
            ['page' => '1.5'],
            ['perPage' => '0'],
            ['perPage' => '-1'],
            ['perPage' => 'abc'],
            ['perPage' => '201'],
        ];

        foreach ($cases as $query) {
            try {
                Pagination::fromQuery($query);
                $this->fail('ожидалась 422 для ' . json_encode($query));
            } catch (ApiException $e) {
                $this->assertSame(422, $e->httpStatus(), json_encode($query));
                $this->assertSame('VALIDATION_FAILED', $e->errorCode());
            }
        }
    }

    public function testIgnoresArrayValues(): void
    {
        // page[]=2 приходит из $_GET как массив: трактуем как отсутствие.
        $pagination = Pagination::fromQuery(['page' => ['2'], 'perPage' => ['10']]);

        $this->assertSame(1, $pagination->page);
        $this->assertSame(50, $pagination->perPage);
    }

    public function testFromRequestReadsQuery(): void
    {
        $request = new Request('GET', '/api/v1/canvases', ['page' => '2', 'perPage' => '5'], [], '', []);
        $pagination = Pagination::fromRequest($request);

        $this->assertSame(2, $pagination->page);
        $this->assertSame(5, $pagination->perPage);
    }

    public function testEnvelope(): void
    {
        $pagination = Pagination::fromQuery(['page' => '2', 'perPage' => '2']);
        $envelope = $pagination->envelope([['id' => 'a'], ['id' => 'b']], 7);

        $this->assertSame(
            ['items' => [['id' => 'a'], ['id' => 'b']], 'page' => 2, 'perPage' => 2, 'total' => 7],
            $envelope,
        );
    }
}
