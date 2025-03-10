<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

trait FuzzySearchable
{
    /**
     * Apply a fuzzy search scope to the query.
     *
     * This scope enables fuzzy searching on specified columns of an Eloquent model.
     * It supports multiple matching strategies (exact, start/end of word, word boundary,
     * permutation, and soundex matches) and orders results by match priority and quality.
     *
     * @param Builder $query The Eloquent query builder instance
     * @param string $searchTerm The term to search for
     * @param array $columns Columns to search in (default: ['name'])
     * @param bool $debugLogging Whether to log SQL queries and bindings for debugging (default: false)
     * @return Builder The modified query builder with fuzzy search applied
     */
    public function scopeFuzzySearch(Builder $query, string $searchTerm, array $columns = ['name'], bool $debugLogging = false): Builder
    {
        $searchTerm = trim(strtolower($searchTerm));

        if (empty($searchTerm)) {
            return $query;
        }

        // Get the table name from the model
        $table = $this->getTable();

        // Track the original query to maintain all existing conditions
        $originalQuery = clone $query;
        $baseQuerySql = $originalQuery->toSql();
        $baseQueryBindings = $originalQuery->getBindings();

        // Generate permutations for the search term
        $permutations = $this->generatePermutations($searchTerm);

        if ($debugLogging) {
            Log::info('Base Query Info:', [
                'sql' => $baseQuerySql,
                'bindings' => $baseQueryBindings,
                'search_term' => $searchTerm,
                'permutations' => $permutations
            ]);
        }

        // Build subqueries for prioritized matching
        $subqueries = [];
        $bindings = [];

        // 1. Exact matches (highest priority)
        foreach ($columns as $column) {
            $subqueries[] = "
                SELECT t.*, 1 as priority, 1 as quality
                FROM ($baseQuerySql) AS t
                WHERE LOWER($column) LIKE ?
            ";
            $bindings = array_merge($bindings, $baseQueryBindings, ['%' . $searchTerm . '%']);
        }

        // 2. Start of word matches
        foreach ($columns as $column) {
            $subqueries[] = "
                SELECT t.*, 2 as priority, 1 as quality
                FROM ($baseQuerySql) AS t
                WHERE LOWER($column) LIKE ?
            ";
            $bindings = array_merge($bindings, $baseQueryBindings, [$searchTerm . '%']);
        }

        // 3. End of word matches
        foreach ($columns as $column) {
            $subqueries[] = "
                SELECT t.*, 2 as priority, 2 as quality
                FROM ($baseQuerySql) AS t
                WHERE LOWER($column) LIKE ?
            ";
            $bindings = array_merge($bindings, $baseQueryBindings, ['%' . $searchTerm]);
        }

        // 4. Word boundary matches
        foreach ($columns as $column) {
            $subqueries[] = "
                SELECT t.*, 2 as priority, 3 as quality
                FROM ($baseQuerySql) AS t
                WHERE LOWER($column) LIKE ? OR LOWER($column) LIKE ? OR LOWER($column) LIKE ?
            ";
            $bindings = array_merge(
                $bindings,
                $baseQueryBindings,
                [
                    '% ' . $searchTerm . ' %',  // Space-bounded
                    '% ' . $searchTerm . '%',   // Space before
                    '%' . $searchTerm . ' %'    // Space after
                ]
            );
        }

        // 5. Permutation matches
        if (!empty($permutations)) {
            foreach ($columns as $column) {
                $conditions = [];
                $permBindings = [];

                foreach ($permutations as $perm) {
                    $conditions[] = "LOWER($column) LIKE ?";
                    $permBindings[] = '%' . $perm . '%';
                }

                if (!empty($conditions)) {
                    $subqueries[] = "
                        SELECT t.*, 3 as priority, 1 as quality
                        FROM ($baseQuerySql) AS t
                        WHERE " . implode(' OR ', $conditions);
                    $bindings = array_merge($bindings, $baseQueryBindings, $permBindings);
                }
            }
        }

        // 6. Soundex matches (if possible)
        foreach ($columns as $column) {
            $subqueries[] = "
                SELECT t.*, 3 as priority, 2 as quality
                FROM ($baseQuerySql) AS t
                WHERE SOUNDEX($column) = SOUNDEX(?)
            ";
            $bindings = array_merge($bindings, $baseQueryBindings, [$searchTerm]);
        }

        // Get column names for the main query
        $tableColumns = $this->getModelColumns();

        // Combine subqueries with UNION ALL
        $mainQuery = "
            WITH matched_results AS (
                " . implode("\nUNION ALL\n", $subqueries) . "
            )
            SELECT DISTINCT 
                " . implode(', ', $tableColumns) . ",
                MIN(priority) as search_priority,
                MIN(quality) as match_quality
            FROM matched_results
            GROUP BY " . implode(', ', $tableColumns) . "
            ORDER BY 
                search_priority ASC,
                match_quality ASC,
                " . $columns[0] . " ASC
        ";

        if ($debugLogging) {
            Log::info('Fuzzy Search Query:', [
                'sql' => $mainQuery,
                'bindings' => $bindings,
                'binding_count' => count($bindings)
            ]);
        }

        try {
            $results = DB::select($mainQuery, $bindings);

            // If no results, return an empty query
            if (empty($results)) {
                return $query->whereRaw('1 = 0');
            }

            // Extract IDs for the final query
            $ids = array_map(function ($result) {
                return $result->id;
            }, $results);

            // Reset the query and apply the ID filter with proper ordering
            return $query->whereIn('id', $ids)
                ->orderByRaw('FIELD(id, ' . implode(',', $ids) . ')');
        } catch (\Exception $e) {
            if ($debugLogging) {
                Log::error('Fuzzy Search Error:', [
                    'error' => $e->getMessage(),
                    'sql' => $mainQuery,
                    'bindings' => $bindings
                ]);
            }
            return $query->whereRaw('1 = 0');
        }
    }

    /**
     * Get the model's column names.
     *
     * Retrieves the list of column names for the model's table using the Schema facade.
     * Falls back to a default set of columns if the schema cannot be retrieved.
     *
     * @return array List of column names
     */
    protected function getModelColumns(): array
    {
        $table = $this->getTable();
        try {
            $columns = Schema::getColumnListing($table);
            // Fallback if schema cannot be determined
            if (empty($columns)) {
                return ['id', 'created_at', 'updated_at'];
            }
            return $columns;
        } catch (\Exception $e) {
            // Basic columns as fallback
            return ['id', 'created_at', 'updated_at'];
        }
    }

    /**
     * Generate permutations for fuzzy matching.
     *
     * Creates variations of the search term to account for typos or alternate spellings.
     * Includes character swaps, omissions (for short terms), and common substitutions.
     *
     * @param string $term The search term to permute
     * @return array Array of unique permuted terms
     */
    private function generatePermutations(string $term): array
    {
        $permutations = [];
        $length = strlen($term);

        // Skip if term is too long to avoid excessive permutations
        if ($length > 10) {
            return [$term];
        }

        // Character swaps
        for ($i = 0; $i < $length - 1; $i++) {
            $swapped = substr($term, 0, $i) .
                $term[$i + 1] .
                $term[$i] .
                substr($term, $i + 2);
            $permutations[] = $swapped;
        }

        // Character omissions (only for terms 5 chars or less)
        if ($length <= 5) {
            for ($i = 0; $i < $length; $i++) {
                $omitted = substr($term, 0, $i) . substr($term, $i + 1);
                $permutations[] = $omitted;
            }
        }

        // Common character substitutions for short terms
        if ($length <= 5) {
            $substitutions = [
                'a' => ['e'],
                'e' => ['a', 'i'],
                'i' => ['e', 'y'],
                'o' => ['u'],
                'u' => ['o'],
                's' => ['z'],
                'z' => ['s'],
                'ph' => ['f'],
                'f' => ['ph']
            ];

            foreach ($substitutions as $char => $alternatives) {
                if (strpos($term, $char) !== false) {
                    foreach ($alternatives as $alt) {
                        $permutations[] = str_replace($char, $alt, $term);
                    }
                }
            }
        }

        return array_unique($permutations);
    }
}
