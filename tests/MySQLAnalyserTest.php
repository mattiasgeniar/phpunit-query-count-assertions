<?php

namespace Mattiasgeniar\PhpunitQueryCountAssertions\Tests;

use Mattiasgeniar\PhpunitQueryCountAssertions\Contracts\ConnectionInterface;
use Mattiasgeniar\PhpunitQueryCountAssertions\QueryAnalysers\MySQLAnalyser;
use Mattiasgeniar\PhpunitQueryCountAssertions\QueryAnalysers\QueryIssue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MySQLAnalyserTest extends TestCase
{
    #[Test]
    public function it_analyzes_json_explain_results(): void
    {
        $analyser = new MySQLAnalyser;

        $explain = [
            'query_block' => [
                'table' => [
                    'table_name' => 'users',
                    'access_type' => 'ALL',
                    'rows_examined_per_scan' => 150,
                    'filtered' => 10,
                    'possible_keys' => ['idx_name'],
                    'key' => null,
                    'using_filesort' => true,
                    'using_temporary_table' => true,
                ],
            ],
        ];

        $issues = $analyser->analyzeIndexUsage($explain);
        $messages = $this->extractIssueMessages($issues);

        $this->assertContains("Full table scan on 'users'", $messages);
        $this->assertContains("Index available but not used on 'users'", $messages);
        $this->assertContains("Using filesort on 'users'", $messages);
        $this->assertContains("Using temporary table on 'users'", $messages);
    }

    #[Test]
    public function it_counts_rows_from_json_explain(): void
    {
        $analyser = new MySQLAnalyser;

        $explain = [
            'query_block' => [
                'table' => [
                    'table_name' => 'users',
                    'rows_examined_per_scan' => 10,
                ],
                'nested_loop' => [
                    [
                        'table' => [
                            'table_name' => 'posts',
                            'rows_examined_per_scan' => 20,
                        ],
                    ],
                    [
                        'table' => [
                            'table_name' => 'comments',
                            'rows_examined_per_scan' => 5,
                        ],
                    ],
                ],
            ],
        ];

        $this->assertSame(35, $analyser->getRowsExamined($explain));
    }

    #[Test]
    #[DataProvider('rowThresholdProvider')]
    public function it_respects_row_threshold_for_index_warnings_in_json_explain(int $rows, bool $shouldWarn): void
    {
        $analyser = new MySQLAnalyser;

        $explain = [
            'query_block' => [
                'table' => [
                    'table_name' => 'users',
                    'access_type' => 'ALL',
                    'rows_examined_per_scan' => $rows,
                    'possible_keys' => ['idx_name'],
                    'key' => null,
                ],
            ],
        ];

        $issues = $analyser->analyzeIndexUsage($explain);
        $messages = $this->extractIssueMessages($issues);
        $unusedIndexMessage = "Index available but not used on 'users'";
        $suppressedMessage = $this->suppressedUnusedIndexMessage($rows);

        if ($shouldWarn) {
            $this->assertContains($unusedIndexMessage, $messages);
            $this->assertNotContains($suppressedMessage, $messages);

            return;
        }

        $this->assertNotContains($unusedIndexMessage, $messages);
        $this->assertContains($suppressedMessage, $messages);
    }

    public static function rowThresholdProvider(): array
    {
        return [
            'below threshold' => [9, false],
            'at threshold' => [10, true],
            'above threshold' => [11, true],
        ];
    }

    #[Test]
    #[DataProvider('rowThresholdProvider')]
    public function it_respects_row_threshold_for_index_warnings_in_tabular_explain(int $rows, bool $shouldWarn): void
    {
        $analyser = new MySQLAnalyser;

        $explain = [
            (object) [
                'table' => 'users',
                'type' => 'ALL',
                'rows' => $rows,
                'possible_keys' => 'idx_name',
                'key' => null,
                'Extra' => '',
            ],
        ];

        $issues = $analyser->analyzeIndexUsage($explain);
        $messages = $this->extractIssueMessages($issues);
        $unusedIndexMessage = "Index available but not used on 'users'";
        $suppressedMessage = $this->suppressedUnusedIndexMessage($rows);

        if ($shouldWarn) {
            $this->assertContains($unusedIndexMessage, $messages);
            $this->assertNotContains($suppressedMessage, $messages);

            return;
        }

        $this->assertNotContains($unusedIndexMessage, $messages);
        $this->assertContains($suppressedMessage, $messages);
    }

    #[Test]
    #[DataProvider('rowThresholdProvider')]
    public function it_respects_row_threshold_for_full_index_scan_in_json_explain(int $rows, bool $shouldWarn): void
    {
        $analyser = new MySQLAnalyser;

        $explain = [
            'query_block' => [
                'table' => [
                    'table_name' => 'users',
                    'access_type' => 'index',
                    'rows_examined_per_scan' => $rows,
                    'key' => 'idx_name',
                ],
            ],
        ];

        $issues = $analyser->analyzeIndexUsage($explain);
        $messages = $this->extractIssueMessages($issues);
        $fullIndexScanMessage = "Full index scan on 'users'";

        if ($shouldWarn) {
            $this->assertContains($fullIndexScanMessage, $messages);

            return;
        }

        $this->assertNotContains($fullIndexScanMessage, $messages);
    }

    #[Test]
    #[DataProvider('rowThresholdProvider')]
    public function it_respects_row_threshold_for_full_index_scan_in_tabular_explain(int $rows, bool $shouldWarn): void
    {
        $analyser = new MySQLAnalyser;

        $explain = [
            (object) [
                'table' => 'users',
                'type' => 'index',
                'rows' => $rows,
                'key' => 'idx_name',
                'Extra' => '',
            ],
        ];

        $issues = $analyser->analyzeIndexUsage($explain);
        $messages = $this->extractIssueMessages($issues);
        $fullIndexScanMessage = "Full index scan on 'users'";

        if ($shouldWarn) {
            $this->assertContains($fullIndexScanMessage, $messages);

            return;
        }

        $this->assertNotContains($fullIndexScanMessage, $messages);
    }

    #[Test]
    public function it_warns_on_full_index_scan_when_rows_is_null(): void
    {
        $analyser = new MySQLAnalyser;

        $explain = [
            'query_block' => [
                'table' => [
                    'table_name' => 'users',
                    'access_type' => 'index',
                    'key' => 'idx_name',
                ],
            ],
        ];

        $issues = $analyser->analyzeIndexUsage($explain);
        $messages = $this->extractIssueMessages($issues);

        $this->assertContains("Full index scan on 'users'", $messages);
    }

    #[Test]
    public function it_memoizes_json_explain_support_by_connection(): void
    {
        $analyser = new MySQLAnalyser;

        $connection = $this->createMock(ConnectionInterface::class);
        $versionCalls = 0;
        $explainCalls = 0;

        $connection->method('selectOne')
            ->willReturnCallback(function (string $query, array $_bindings = []) use (&$versionCalls, &$explainCalls) {
                if (str_starts_with($query, 'SELECT VERSION()')) {
                    $versionCalls++;

                    return (object) ['version' => '8.0.33'];
                }

                if (str_starts_with($query, 'EXPLAIN FORMAT=JSON')) {
                    $explainCalls++;

                    return (object) [
                        'EXPLAIN' => json_encode([
                            'query_block' => [
                                'table' => [
                                    'table_name' => 'users',
                                    'access_type' => 'const',
                                ],
                            ],
                        ]),
                    ];
                }

                return null;
            });

        $analyser->explain($connection, 'SELECT * FROM users', []);
        $analyser->explain($connection, 'SELECT * FROM users WHERE id = ?', [1]);

        $this->assertSame(1, $versionCalls);
        $this->assertSame(2, $explainCalls);
    }

    /**
     * @param  array<int, QueryIssue>  $issues
     * @return array<int, string>
     */
    private function extractIssueMessages(array $issues): array
    {
        return array_map(fn ($issue) => $issue->message, $issues);
    }

    private function suppressedUnusedIndexMessage(int $rows, int $threshold = 10): string
    {
        return "Index available but not used on 'users' (rows {$rows} < {$threshold}; small tables often scan faster). Seed more data or lower minRowsForScanWarning.";
    }
}
