---

# FuzzySearchable Trait for Laravel

The `FuzzySearchable` trait adds advanced fuzzy search capabilities to Laravel Eloquent models. It enables flexible, user-friendly searches by matching terms across various strategies, including exact matches, partial word matches, typo-tolerant permutations, and phonetic (soundex) matches. Results are prioritized and ordered based on match relevance.

## Features

- **Multiple Matching Strategies:**
  - Exact matches
  - Start/end of word matches
  - Word boundary matches
  - Permutation matches (for typos)
  - Soundex matches (phonetic similarity)
- **Prioritized Results:** Matches are ordered by priority (e.g., exact matches first) and quality.
- **Debug Logging:** Optional logging of SQL queries and bindings for troubleshooting.
- **Customizable Columns:** Search across one or multiple model columns.

## Installation

1. **Add the Trait:**
   - Create a `Traits` directory in your Laravel project’s `app` folder (e.g., `app/Traits`), if it doesn’t already exist.
   - Save the `FuzzySearchable` trait as `FuzzySearchable.php` in `app/Traits`.

2. **Use the Trait in a Model:**
   - Include the trait in any Eloquent model where you want fuzzy search functionality.

   ```php
   namespace App\Models;

   use App\Traits\FuzzySearchable;
   use Illuminate\Database\Eloquent\Model;

   class Product extends Model
   {
       use FuzzySearchable;

       protected $fillable = ['name', 'description', 'price'];
   }
   ```

## Usage

The trait provides a `fuzzySearch` scope that you can call on your model’s query builder. The scope accepts the following parameters:

- **`$searchTerm` (string):** The term to search for.
- **`$columns` (array, optional):** Columns to search in (default: `['name']`).
- **`$debugLogging` (bool, optional):** Enable logging of SQL queries and bindings (default: `false`).

### Basic Example

Search for products with names similar to "laptop":

```php
$products = Product::fuzzySearch('laptop')->get();
```

This searches the `name` column (default) for "laptop" and its variations (e.g., "laptp", "lap top").

### Multi-Column Search

Search across multiple columns:

```php
$products = Product::fuzzySearch('laptop', ['name', 'description'])->get();
```

This finds records where "laptop" or its variations appear in either the `name` or `description`.

### With Debug Logging

Enable debug logging to troubleshoot the generated SQL:

```php
$products = Product::fuzzySearch('laptop', ['name'], true)->get();
```

Check your Laravel logs for detailed query information.

### Chaining with Other Queries

Combine `fuzzySearch` with other Eloquent query methods:

```php
$products = Product::where('price', '>', 500)
                   ->fuzzySearch('laptop', ['name'])
                   ->orderBy('price', 'asc')
                   ->paginate(10);
```

This finds products over $500 with "laptop" in the name, ordered by price, with pagination.

### Handling Empty Results

If no matches are found, the query returns an empty result set:

```php
$products = Product::fuzzySearch('nonexistentterm')->get(); // Returns empty collection
```

## How It Works

The `fuzzySearch` scope builds a complex query with multiple subqueries, each representing a matching strategy:

1. **Exact Matches:** Searches for the term anywhere in the column (priority 1).
2. **Start of Word Matches:** Matches the term at the start of the column (priority 2).
3. **End of Word Matches:** Matches the term at the end of the column (priority 2).
4. **Word Boundary Matches:** Matches the term as a whole word (priority 2).
5. **Permutation Matches:** Matches typos or variations of the term (priority 3).
6. **Soundex Matches:** Matches phonetically similar terms (priority 3).

These subqueries are combined with `UNION ALL`, deduplicated, and ordered by priority and quality scores. The final result preserves the original query’s conditions and returns records in the calculated order.

## Additional Notes

- **Performance Considerations:**
  - Fuzzy searching can be resource-intensive on large datasets due to multiple subqueries and permutations. Index the columns you plan to search (`name`, `description`, etc.) for better performance.
  - Permutations are limited to terms ≤10 characters, with additional variations (omissions, substitutions) for terms ≤5 characters, to balance flexibility and performance.

- **Database Compatibility:**
  - The trait uses `SOUNDEX()`, which is MySQL-specific. For PostgreSQL or SQLite, you’ll need to modify the soundex logic (e.g., use `SOUNDEX()` alternatives or remove it).

- **Error Handling:**
  - If an error occurs (e.g., database connection issues), the trait logs the error (if `$debugLogging` is true) and returns an empty result set.

- **Customization:**
  - Modify `generatePermutations()` to adjust typo tolerance (e.g., add more substitutions).
  - Adjust priority and quality scores in the subqueries to tweak result ordering.

## Example Use Cases

1. **E-commerce Search:**
   - Allow customers to find "phone" even if they type "phoen" or "fone".

   ```php
   $products = Product::fuzzySearch('phoen', ['name', 'category'])->get();
   ```

2. **Autocomplete Suggestions:**
   - Provide real-time suggestions as users type.

   ```php
   $suggestions = Product::fuzzySearch($request->input('q'), ['name'])
                         ->limit(5)
                         ->pluck('name');
   ```

3. **Admin Search Tool:**
   - Search across multiple fields with filtering.

   ```php
   $results = User::where('role', 'customer')
                  ->fuzzySearch('jon', ['first_name', 'last_name', 'email'])
                  ->get();
   ```

---