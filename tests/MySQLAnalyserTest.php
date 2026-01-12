<?php

namespace Mattiasgeniar\PhpunitQueryCountAssertions\Tests;

use Illuminate\Database\Connection;
use Mattiasgeniar\PhpunitQueryCountAssertions\QueryAnalysers\MySQLAnalyser;
use PHPUnit\Framework\Attributes\Test;

class MySQLAnalyserTest extends \PHPUnit\Framework\TestCase
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
        $messages = array_map(fn ($issue) => $issue->message, $issues);

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
    public function it_memoizes_json_explain_support_by_connection(): void
    {
        $analyser = new MySQLAnalyser;

        $connection = $this->createMock(Connection::class);
        $versionCalls = 0;
        $explainCalls = 0;

        $connection->method('selectOne')
            ->willReturnCallback(function (string $query, array $bindings = []) use (&$versionCalls, &$explainCalls) {
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
}
