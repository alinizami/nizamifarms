<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CRM\ProductModel;
use App\Services\ShopifyService;
use App\Services\WooCommerceService;
use Illuminate\Support\Facades\Log;

class ProductController extends Controller
{
    protected $shopify;
    protected $wooCommerce;

    public function __construct(ShopifyService $shopify, WooCommerceService $wooCommerce)
    {
        $this->shopify = $shopify;
        $this->wooCommerce = $wooCommerce;
    }

    /**
     * Attribute settings UI (labels from JSON; groups are transient via request form only)
     */
    public function attributes()
    {
        // Store the previous URL (with filters) in session for "Back to Products" button
        if (url()->previous() !== url()->current()) {
            $previousUrl = url()->previous();
            // Only store if it's from the products index page
            if (strpos($previousUrl, route('products.index')) !== false || strpos($previousUrl, '/products') !== false) {
                session(['products_return_url' => $previousUrl]);
            }
        }
        
        $labels = $this->readAttributeLabels();
        $activeKey = (int) request()->get('level', 1);
        $auto = $this->readAttributeAutoRules();
        $activeRules = $auto[(string) $activeKey] ?? [];
        // Sort by priority desc for display
        usort($activeRules, function($a,$b){ return ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0); });
        // Current DB assignments summary for the selected level (for info only)
        $column = 'attribute_' . $activeKey;
        $assignStats = \DB::table('t_crm_prod_product')
            ->select($column . ' as value', \DB::raw('COUNT(*) as cnt'))
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->groupBy($column)
            ->orderByDesc('cnt')
            // Removed limit to show ALL categories, not just top 20
            ->get();
        
        // Get existing categories for the dropdown (for all levels)
        $existingCategories = [
            '1' => \DB::table('t_crm_prod_product')
                ->select('attribute_1 as value')
                ->whereNotNull('attribute_1')
                ->where('attribute_1', '!=', '')
                ->groupBy('attribute_1')
                ->orderBy('attribute_1')
                ->pluck('value')
                ->toArray(),
            '2' => \DB::table('t_crm_prod_product')
                ->select('attribute_2 as value')
                ->whereNotNull('attribute_2')
                ->where('attribute_2', '!=', '')
                ->groupBy('attribute_2')
                ->orderBy('attribute_2')
                ->pluck('value')
                ->toArray(),
            '3' => \DB::table('t_crm_prod_product')
                ->select('attribute_3 as value')
                ->whereNotNull('attribute_3')
                ->where('attribute_3', '!=', '')
                ->groupBy('attribute_3')
                ->orderBy('attribute_3')
                ->pluck('value')
                ->toArray(),
        ];
        
        // Check if user has permission to edit priorities (Taimur role only)
        $user = auth()->user();
        $canEditPriorities = false;
        if ($user) {
            // Check if user has Taimur role (by role name, not ID)
            $canEditPriorities = $user->roles()
                ->whereRaw('LOWER(urole_name) = ?', ['taimur'])
                ->exists();
        }
        
        return view('pages.products.attributes', compact('labels', 'activeKey', 'activeRules', 'assignStats', 'existingCategories', 'canEditPriorities'));
    }

    public function saveAttributeLabels(Request $request)
    {
        $labels = $this->readAttributeLabels();
        foreach ([1,2,3] as $key) {
            $label = trim((string) $request->input('label_'.$key));
            if ($label !== '') {
                $labels[(string)$key] = $label;
            }
        }
        // Optional auto-rules payload (when sent from UI). Persist to JSON file.
        $autoRules = $request->input('auto_rules');
        if (is_array($autoRules)) {
            $this->writeAttributeAutoRules($autoRules);
        }
        $this->writeAttributeLabels($labels);
        return back()->with('success', 'Attribute labels saved.');
    }

    // Removed persistent group creation; groups become transient via UI for one-time apply

    // Removed explicit group persistence paths

    public function applyAttributeRules(Request $request)
    {
        $validated = $request->validate([
            'attribute_key' => 'required|in:1,2,3',
            'group_name' => 'required|string|max:150',
            'match_string' => 'nullable|string|max:255',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'integer',
            'product_type' => 'nullable|string',
            'vendor' => 'nullable|string',
        ]);

        $attributeKey = (int) $validated['attribute_key'];
        $column = 'attribute_' . $attributeKey;

        $query = ProductModel::query();
        if (!empty($validated['product_type'])) $query->where('product_type', $validated['product_type']);
        if (!empty($validated['vendor'])) $query->where('vendor', $validated['vendor']);

        $products = $query->get();
        $updated = 0;

        foreach ($products as $product) {
            $assign = false;
            if (!empty($validated['match_string'])) {
                if (stripos($product->title ?? '', $validated['match_string']) !== false) {
                    $assign = true;
                }
            }
            if (!$assign && !empty($validated['product_ids']) && in_array($product->id, $validated['product_ids'])) {
                $assign = true;
            }
            if ($assign) {
                $oldValue = $product->{$column};
                $newValue = $validated['group_name'];
                
                if ($oldValue !== $newValue) {
                    // Log the change
                    $levelNum = str_replace('attribute_', '', $column);
                    \App\Models\CRM\ProductChangeHistory::logChange([
                        'product_id' => $product->id,
                        'change_type' => \App\Models\CRM\ProductChangeHistory::TYPE_CATEGORY_CHANGE,
                        'field_name' => "Category Level {$levelNum}",
                        'old_value' => $oldValue,
                        'new_value' => $newValue,
                        'change_source' => 'web',
                        'notes' => $validated['match_string'] 
                            ? "Applied rule: title contains '{$validated['match_string']}'" 
                            : 'Manual selection',
                    ]);
                }
                
                $product->{$column} = $newValue;
                $product->save();
                $updated++;
            }
        }

        return back()->with('success', "Applied to {$updated} products.");
    }

    public function previewAttributeRules(Request $request)
    {
        $validated = $request->validate([
            'attribute_key' => 'required|in:1,2,3',
            'group_name' => 'required|string|max:150',
            'match_string' => 'nullable|string|max:255',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'integer',
            'product_type' => 'nullable|string',
            'vendor' => 'nullable|string',
        ]);

        $query = ProductModel::query();
        if (!empty($validated['product_type'])) $query->where('product_type', $validated['product_type']);
        if (!empty($validated['vendor'])) $query->where('vendor', $validated['vendor']);

        $products = $query->select('id','title')->get();
        $count = 0;
        $sample = [];
        foreach ($products as $product) {
            $assign = false;
            if (!empty($validated['match_string'])) {
                if (stripos($product->title ?? '', $validated['match_string']) !== false) {
                    $assign = true;
                }
            }
            if (!$assign && !empty($validated['product_ids']) && in_array($product->id, $validated['product_ids'])) {
                $assign = true;
            }
            if ($assign) {
                $count++;
                if (count($sample) < 10) $sample[] = ['id' => $product->id, 'title' => $product->title];
            }
        }

        return response()->json(['success' => true, 'count' => $count, 'sample' => $sample]);
    }

    private function labelsPath(): string
    {
        return storage_path('app/private/attribute_labels.json');
    }

    private function readAttributeLabels(): array
    {
        $path = $this->labelsPath();
        $defaults = ['1' => 'Category Level 1', '2' => 'Category Level 2', '3' => 'Category Level 3'];
        if (!is_file($path)) { return $defaults; }
        $json = file_get_contents($path);
        $data = json_decode($json, true) ?: [];
        
        // Handle both numeric array format and string key format
        $normalized = $defaults;
        foreach ([1, 2, 3] as $key) {
            $stringKey = (string)$key;
            if (isset($data[$stringKey])) {
                $normalized[$stringKey] = $data[$stringKey];
            } elseif (isset($data[$key])) {
                $normalized[$stringKey] = $data[$key];
            }
            
            // Normalize any legacy 'Attribute X' labels to Category Level X
            $v = (string)($normalized[$stringKey] ?? '');
            if (preg_match('/^\s*Attribute\s*' . $key . '\s*$/i', $v)) {
                $normalized[$stringKey] = $defaults[$stringKey];
            }
        }
        return $normalized;
    }

    private function writeAttributeLabels(array $labels): void
    {
        $path = $this->labelsPath();
        if (!is_dir(dirname($path))) @mkdir(dirname($path), 0775, true);
        
        // Ensure we save with string keys for consistency
        $normalized = [];
        foreach ([1, 2, 3] as $key) {
            $stringKey = (string)$key;
            $normalized[$stringKey] = $labels[$stringKey] ?? ('Category Level ' . $key);
        }
        
        file_put_contents($path, json_encode($normalized, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    private function autoRulesPath(): string
    {
        return storage_path('app/private/attribute_auto_rules.json');
    }

    private function readAttributeAutoRules(): array
    {
        $path = $this->autoRulesPath();
        if (!is_file($path)) return ['1'=>[], '2'=>[], '3'=>[]];
        $data = json_decode(file_get_contents($path), true) ?: [];
        
        // Handle both numeric array format and string key format
        $normalized = ['1'=>[], '2'=>[], '3'=>[]];
        foreach ([1, 2, 3] as $key) {
            $stringKey = (string)$key;
            if (isset($data[$stringKey])) {
                $normalized[$stringKey] = $data[$stringKey];
            } elseif (isset($data[$key])) {
                $normalized[$stringKey] = $data[$key];
            }
        }
        return $normalized;
    }

    private function writeAttributeAutoRules(array $rules): void
    {
        $path = $this->autoRulesPath();
        if (!is_dir(dirname($path))) @mkdir(dirname($path), 0775, true);
        
        // Ensure we save with string keys for consistency
        $normalized = [];
        foreach ([1, 2, 3] as $key) {
            $stringKey = (string)$key;
            $normalized[$stringKey] = $rules[$stringKey] ?? [];
        }
        
        file_put_contents($path, json_encode($normalized, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    // Lightweight product lookup for selectors
    public function lookup(Request $request)
    {
        $q = trim((string) $request->get('q', ''));
        $limit = (int) ($request->get('limit', 15));
        $limit = max(1, min(50, $limit));

        $query = ProductModel::select('id', 'title')
            ->when($q !== '', function ($builder) use ($q) {
                $builder->where('title', 'like', "%{$q}%");
            })
            ->orderBy('title')
            ->limit($limit)
            ->get();

        return response()->json(['success' => true, 'products' => $query]);
    }

    public function saveAutoRules(Request $request)
    {
        try {
            $validated = $request->validate([
                'attribute_key' => 'required|in:1,2,3',
                'rules' => 'nullable|array', // Changed from 'required' to 'nullable' to allow empty array
                'rules.*.match' => 'required|string',
                'rules.*.group' => 'required|string',
                'rules.*.priority' => 'nullable|integer',
            ]);
            
            // Ensure rules is an array even if null
            $validated['rules'] = $validated['rules'] ?? [];
        $all = $this->readAttributeAutoRules();
        $all[(string)$validated['attribute_key']] = array_values($validated['rules']);
        $this->writeAttributeAutoRules($all);
        
        // Generate summary of rule matches
        $attributeKey = $validated['attribute_key'];
        $column = 'attribute_' . $attributeKey;
        $rules = $validated['rules'];
        $summary = [];
        
        foreach ($rules as $rule) {
            $needle = trim((string)($rule['match'] ?? ''));
            $group = trim((string)($rule['group'] ?? ''));
            if ($needle === '' || $group === '') continue;
            
            // Count products that are ACTUALLY assigned to this category
            // (not just products that match the search word)
            $count = \DB::table('t_crm_prod_product')
                ->where($column, '=', $group)
                ->count();
            
            $summary[] = [
                'match' => $needle,
                'group' => $group,
                'priority' => $rule['priority'] ?? 0,
                'matching_products' => $count
            ];
        }
        
        // Count uncategorized products for this level
        $totalProducts = \DB::table('t_crm_prod_product')->count();
        $categorizedProducts = \DB::table('t_crm_prod_product')
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->count();
        $uncategorizedProducts = $totalProducts - $categorizedProducts;
        
        // Get sample of uncategorized products (top 20)
        $uncategorizedSample = \DB::table('t_crm_prod_product')
            ->select('id', 'title', 'product_type', 'vendor')
            ->where(function($q) use ($column) {
                $q->whereNull($column)
                  ->orWhere($column, '=', '');
            })
            ->orderBy('id', 'desc')
            ->limit(20)
            ->get();
        
        return response()->json([
            'success' => true,
            'summary' => $summary,
            'total_products' => $totalProducts,
            'categorized_products' => $categorizedProducts,
            'uncategorized_products' => $uncategorizedProducts,
            'uncategorized_sample' => $uncategorizedSample
        ]);
        
        } catch (\Exception $e) {
            \Log::error('Error in saveAutoRules: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error saving rules: ' . $e->getMessage()
            ], 500);
        }
    }

    // Get coverage summary without saving rules
    public function getCoverageSummary(Request $request)
    {
        try {
            // Validate input
            $validated = $request->validate([
                'attribute_key' => 'required|in:1,2,3'
            ]);
            
            $attributeKey = (int) $validated['attribute_key'];
            
            \Log::info('Getting coverage summary', [
                'attribute_key' => $attributeKey
            ]);
            
            // Read existing saved rules from file
            $allRules = $this->readAttributeAutoRules();
            $rules = $allRules[(string)$attributeKey] ?? [];
            
            \Log::info('Found rules for level', [
                'level' => $attributeKey,
                'rule_count' => count($rules)
            ]);
            
            $column = 'attribute_' . $attributeKey;
            $summary = [];
            
            // Sort rules by priority (descending) for effective count calculation
            $sortedRules = $rules;
            usort($sortedRules, function($a, $b) {
                return ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0);
            });
            
            // Calculate matches for each rule (raw text match count)
            foreach ($rules as $rule) {
                $needle = trim((string)($rule['match'] ?? ''));
                $group = trim((string)($rule['group'] ?? ''));
                if ($needle === '' || $group === '') continue;
                
                // Count products that MATCH the search string (will be categorized when rules are applied)
                // This shows the POTENTIAL coverage, not just already assigned products
                // NOTE: Database may have HTML entities (like &amp;) so we search for both versions
                $count = \DB::table('t_crm_prod_product')
                    ->where(function($q) use ($needle) {
                        $q->where('title', 'LIKE', '%'.$needle.'%');
                        
                        // Also search for HTML-encoded version if needle contains special chars
                        $htmlEncoded = htmlspecialchars($needle, ENT_QUOTES, 'UTF-8');
                        if ($htmlEncoded !== $needle) {
                            $q->orWhere('title', 'LIKE', '%'.$htmlEncoded.'%');
                        }
                        
                        // Also search for decoded version if needle contains HTML entities
                        $htmlDecoded = html_entity_decode($needle, ENT_QUOTES, 'UTF-8');
                        if ($htmlDecoded !== $needle) {
                            $q->orWhere('title', 'LIKE', '%'.$htmlDecoded.'%');
                        }
                    })
                    ->count();
                
                $summary[] = [
                    'match' => $needle,
                    'group' => $group,
                    'priority' => $rule['priority'] ?? 0,
                    'matching_products' => $count,
                    'effective_products' => 0 // Will be calculated below
                ];
            }
            
            // Calculate EFFECTIVE counts (simulating priority-based assignment)
            // This shows how many products will ACTUALLY be categorized under each rule
            // after respecting priority order
            if (!empty($sortedRules)) {
                // Get all products with their titles
                $allProducts = \DB::table('t_crm_prod_product')
                    ->select('id', 'title')
                    ->get();
                
                // Initialize effective counts per rule (keyed by match|||group)
                $effectiveCounts = [];
                foreach ($rules as $rule) {
                    $needle = trim((string)($rule['match'] ?? ''));
                    $group = trim((string)($rule['group'] ?? ''));
                    if ($needle !== '' && $group !== '') {
                        $effectiveCounts[$needle . '|||' . $group] = 0;
                    }
                }
                
                // For each product, find the highest-priority matching rule
                foreach ($allProducts as $product) {
                    $title = $product->title;
                    $bestMatch = null;
                    $highestPriority = -999999;
                    
                    // Check against all rules (sorted by priority descending)
                    foreach ($sortedRules as $rule) {
                        $needle = trim((string)($rule['match'] ?? ''));
                        $group = trim((string)($rule['group'] ?? ''));
                        $priority = (int)($rule['priority'] ?? 0);
                        
                        if ($needle === '' || $group === '') continue;
                        
                        // Check if product title matches this rule (including HTML entity variants)
                        $matches = false;
                        if (stripos($title, $needle) !== false) {
                            $matches = true;
                        } else {
                            // Check HTML-encoded version
                            $htmlEncoded = htmlspecialchars($needle, ENT_QUOTES, 'UTF-8');
                            if ($htmlEncoded !== $needle && stripos($title, $htmlEncoded) !== false) {
                                $matches = true;
                            }
                            // Check decoded version
                            $htmlDecoded = html_entity_decode($needle, ENT_QUOTES, 'UTF-8');
                            if (!$matches && $htmlDecoded !== $needle && stripos($title, $htmlDecoded) !== false) {
                                $matches = true;
                            }
                        }
                        
                        if ($matches && $priority > $highestPriority) {
                            $highestPriority = $priority;
                            $bestMatch = $needle . '|||' . $group;
                        }
                    }
                    
                    // Increment count for the winning rule
                    if ($bestMatch !== null && isset($effectiveCounts[$bestMatch])) {
                        $effectiveCounts[$bestMatch]++;
                    }
                }
                
                // Update summary with effective counts
                foreach ($summary as &$item) {
                    $key = $item['match'] . '|||' . $item['group'];
                    $item['effective_products'] = $effectiveCounts[$key] ?? 0;
                }
                unset($item);
            }
            
            // Count categorized vs uncategorized FOR THIS SPECIFIC LEVEL
            // Total products = all products in the database
            $totalProducts = \DB::table('t_crm_prod_product')->count();
            
            // Categorized products = products that have a value in this level's column
            $categorizedProducts = \DB::table('t_crm_prod_product')
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->count();
            
            // Uncategorized products = products without a value in this level's column
            $uncategorizedProducts = \DB::table('t_crm_prod_product')
                ->where(function($q) use ($column) {
                    $q->whereNull($column)
                      ->orWhere($column, '=', '');
                })
                ->count();
            
            // Get sample of uncategorized products
            $uncategorizedSample = \DB::table('t_crm_prod_product')
                ->select('id', 'title', 'product_type', 'vendor')
                ->where(function($q) use ($column) {
                    $q->whereNull($column)
                      ->orWhere($column, '=', '');
                })
                ->orderBy('id', 'desc')
                ->limit(20)
                ->get();
            
            \Log::info('Coverage calculated', [
                'total' => $totalProducts,
                'categorized' => $categorizedProducts,
                'uncategorized' => $uncategorizedProducts
            ]);
            
            return response()->json([
                'success' => true,
                'summary' => $summary,
                'total_products' => $totalProducts,
                'categorized_products' => $categorizedProducts,
                'uncategorized_products' => $uncategorizedProducts,
                'uncategorized_sample' => $uncategorizedSample
            ]);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('Validation error in getCoverageSummary', [
                'errors' => $e->errors()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Invalid request: ' . json_encode($e->errors())
            ], 422);
            
        } catch (\Exception $e) {
            \Log::error('Error in getCoverageSummary: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error loading coverage: ' . $e->getMessage() . ' (Line: ' . $e->getLine() . ')'
            ], 500);
        }
    }

    // Preview auto-rules against existing products
    public function previewAutoRules(Request $request)
    {
        $attributeKey = (int) $request->get('attribute_key', 0); // 0 means all
        $path = $this->autoRulesPath();
        if (!is_file($path)) {
            return response()->json(['success' => true, 'results' => []]);
        }
        $rules = json_decode(file_get_contents($path), true) ?: [];

        $products = ProductModel::select('id', 'title')->get();
        $results = [];

        $keys = $attributeKey && in_array($attributeKey, [1,2,3]) ? [$attributeKey] : [1,2,3];
        foreach ($keys as $key) {
            $count = 0; $sample = [];
            $rset = $rules[(string)$key] ?? [];
            // Sort by priority desc if provided
            usort($rset, function($a,$b){ return ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0); });
            foreach ($products as $p) {
                $matched = false;
                foreach ($rset as $r) {
                    $needle = (string)($r['match'] ?? '');
                    $group = (string)($r['group'] ?? '');
                    // match must be whole word (use regex word boundaries) and case-insensitive
                    if ($needle !== '' && $group !== '' && preg_match('/\\b' . preg_quote($needle, '/') . '\\b/i', (string)$p->title)) {
                        $matched = true; break;
                    }
                }
                if ($matched) {
                    $count++; if (count($sample) < 10) $sample[] = ['id'=>$p->id,'title'=>$p->title];
                }
            }
            $results[$key] = ['count' => $count, 'sample' => $sample];
        }

        return response()->json(['success' => true, 'results' => $results]);
    }

    /**
     * Get products that match a specific rule or are assigned to a category
     * Used for displaying products in modal when clicking on counts
     */
    public function getProductsByRule(Request $request)
    {
        try {
            $validated = $request->validate([
                'attribute_key' => 'required|in:1,2,3',
                'match_string' => 'nullable|string',
                'category_name' => 'nullable|string',
                'type' => 'required|in:rule,category' // 'rule' = matching products, 'category' = assigned products
            ]);
            
            $attributeKey = (int) $validated['attribute_key'];
            $column = 'attribute_' . $attributeKey;
            $type = $validated['type'];
            
            if ($type === 'rule') {
                // Get products that MATCH the search string (potential coverage)
                $matchString = trim($validated['match_string'] ?? '');
                if ($matchString === '') {
                    return response()->json(['success' => false, 'message' => 'Match string required']);
                }
                
                // NOTE: Database may have HTML entities (like &amp;) so we search for both versions
                $products = \DB::table('t_crm_prod_product')
                    ->where(function($q) use ($matchString) {
                        $q->where('title', 'LIKE', '%'.$matchString.'%');
                        
                        // Also search for HTML-encoded version if matchString contains special chars
                        $htmlEncoded = htmlspecialchars($matchString, ENT_QUOTES, 'UTF-8');
                        if ($htmlEncoded !== $matchString) {
                            $q->orWhere('title', 'LIKE', '%'.$htmlEncoded.'%');
                        }
                        
                        // Also search for decoded version if matchString contains HTML entities
                        $htmlDecoded = html_entity_decode($matchString, ENT_QUOTES, 'UTF-8');
                        if ($htmlDecoded !== $matchString) {
                            $q->orWhere('title', 'LIKE', '%'.$htmlDecoded.'%');
                        }
                    })
                    ->select('id', 'title', 'vendor', 'product_type', $column.' as current_category')
                    ->orderBy('title')
                    ->limit(500)
                    ->get();
                    
                $title = 'Products Matching: "' . $matchString . '"';
                
            } else {
                // Get products ASSIGNED to this category
                $categoryName = trim($validated['category_name'] ?? '');
                if ($categoryName === '') {
                    return response()->json(['success' => false, 'message' => 'Category name required']);
                }
                
                $products = \DB::table('t_crm_prod_product')
                    ->where($column, '=', $categoryName)
                    ->select('id', 'title', 'vendor', 'product_type', $column.' as current_category')
                    ->orderBy('title')
                    ->limit(500)
                    ->get();
                    
                $title = 'Products in Category: "' . $categoryName . '"';
            }
            
            return response()->json([
                'success' => true,
                'products' => $products,
                'count' => $products->count(),
                'title' => $title
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Error getting products by rule: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function applySavedRules(Request $request)
    {
        $attributeKey = (int) $request->validate(['attribute_key' => 'required|in:1,2,3'])['attribute_key'];
        $rules = $this->readAttributeAutoRules();
        $rset = $rules[(string)$attributeKey] ?? [];
        usort($rset, function($a,$b){ return ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0); });
        $column = 'attribute_' . $attributeKey;
        $updated = 0;
        $skipped = 0;
        
        // Get all product IDs that ALREADY have a category assigned (manual entries)
        // These will be PRESERVED and NOT overwritten
        $alreadyCategorizedIds = \DB::table('t_crm_prod_product')
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->pluck('id')
            ->toArray();
        
        // Track which product IDs have been categorized by rules in THIS run (to respect priority)
        $newlyCategorizedIds = [];
        
        // Apply in priority order - highest priority first
        // Higher priority rules will take precedence by categorizing products first
        // BUT only for products that don't already have a category
        foreach ($rset as $rule) {
            $needle = trim((string)($rule['match'] ?? ''));
            $group  = trim((string)($rule['group'] ?? ''));
            if ($needle === '' || $group === '') { continue; }
            
            // Get all products that match this rule AND don't already have a category
            // NOTE: Database may have HTML entities (like &amp;) so we search for both versions
            $matchingProducts = \DB::table('t_crm_prod_product')
                ->where(function($q) use ($needle) {
                    $q->where('title', 'LIKE', '%'.$needle.'%');
                    
                    // Also search for HTML-encoded version if needle contains special chars
                    $htmlEncoded = htmlspecialchars($needle, ENT_QUOTES, 'UTF-8');
                    if ($htmlEncoded !== $needle) {
                        $q->orWhere('title', 'LIKE', '%'.$htmlEncoded.'%');
                    }
                    
                    // Also search for decoded version if needle contains HTML entities
                    $htmlDecoded = html_entity_decode($needle, ENT_QUOTES, 'UTF-8');
                    if ($htmlDecoded !== $needle) {
                        $q->orWhere('title', 'LIKE', '%'.$htmlDecoded.'%');
                    }
                })
                // IMPORTANT: Only include products WITHOUT an existing category
                ->where(function($q) use ($column) {
                    $q->whereNull($column)
                      ->orWhere($column, '=', '');
                })
                ->pluck('id');
            
            // Update only products that haven't been categorized by a higher priority rule in this run
            $toUpdate = $matchingProducts->diff($newlyCategorizedIds)->toArray();
            
            if (!empty($toUpdate)) {
                // Log individual changes for each product
                foreach ($toUpdate as $productId) {
                    $levelNum = str_replace('attribute_', '', $column);
                    \App\Models\CRM\ProductChangeHistory::logChange([
                        'product_id' => $productId,
                        'change_type' => \App\Models\CRM\ProductChangeHistory::TYPE_CATEGORY_CHANGE,
                        'field_name' => "Category Level {$levelNum} (Auto Rule)",
                        'old_value' => null,
                        'new_value' => $group,
                        'change_source' => 'system',
                        'notes' => "Applied saved rule: title contains '{$needle}'",
                    ]);
                }
                
                $count = \DB::table('t_crm_prod_product')
                    ->whereIn('id', $toUpdate)
                    ->update([$column => $group, 'updated_at' => now()]);
                $updated += (int) $count;
                
                // Mark these products as categorized so lower priority rules don't override
                $newlyCategorizedIds = array_merge($newlyCategorizedIds, $toUpdate);
            }
        }
        
        // Count how many products were skipped because they already had a category
        $skipped = count($alreadyCategorizedIds);
        
        // NOTE: We no longer clear categories from products that don't match rules
        // This preserves manual category assignments
        // If you need to clear a product's category, do it manually on the product edit page
        
        return response()->json([
            'success' => true, 
            'updated' => $updated, 
            'skipped' => $skipped,
            'message' => $updated > 0 
                ? "Updated {$updated} products. {$skipped} products with existing categories were preserved."
                : ($skipped > 0 
                    ? "No new products to categorize. {$skipped} products already have categories assigned."
                    : "No matching products found.")
        ]);
    }

    public function index(Request $request)
    {
        // Get products with variants and business unit
        $query = ProductModel::with(['variants', 'businessUnit']);

        // 📦 The phone's frozen / store inventory screens (Khaas Shop Inventory, frozen
        // Products, storage picker) list ONE business unit's products — bundles are not
        // physical stock, so they stay out there. The web Products page and the phone's
        // ordinary Products list (no business unit) still show them.
        if ($request->is('api/*') && $request->filled('business_unit_id')) {
            \App\Services\CRM\ProductBundle::excludeBundles($query);
        }

        // Add search functionality
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            
            // Split search query into individual words for flexible matching
            $searchWords = array_filter(explode(' ', $search));
            
            $query->where(function($q) use ($search, $searchWords) {
                // If multiple words, match each word independently in title
                if (count($searchWords) > 1) {
                    $q->where(function($titleQuery) use ($searchWords) {
                        foreach ($searchWords as $word) {
                            $titleQuery->where('title', 'LIKE', "%{$word}%");
                        }
                    });
                } else {
                    // Single word search - keep original behavior
                    $q->where('title', 'LIKE', "%{$search}%");
                }
                
                // Also search in vendor, product_type, and variants
                $q->orWhere('vendor', 'LIKE', "%{$search}%")
                  ->orWhere('product_type', 'LIKE', "%{$search}%")
                  ->orWhereHas('variants', function($vq) use ($search) {
                      $vq->where('sku', 'LIKE', "%{$search}%")
                        ->orWhere('title', 'LIKE', "%{$search}%");
                  });
            });
        }

        // Filter by status
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        // Filter by lean/non-lean
        if ($request->has('is_lean') && $request->is_lean !== '' && $request->is_lean !== null) {
            $query->where('is_lean', (int) $request->is_lean);
        }

        // Filter by product category (product_type)
        if ($request->has('product_type') && $request->product_type) {
            $query->where('product_type', $request->product_type);
        }

        // Filter by attributes
        foreach (['attribute_1', 'attribute_2', 'attribute_3'] as $attr) {
            if ($request->has($attr) && $request->$attr) {
                $query->where($attr, $request->$attr);
            }
        }

        // Filter by vendor
        if ($request->has('vendor') && $request->vendor) {
            $query->where('vendor', $request->vendor);
        }

        // Filter by sync_status
        if ($request->has('sync_status') && $request->sync_status) {
            $query->where('sync_status', $request->sync_status);
        }

        // Filter by business_unit_id (used by Khaas Mode to show only BU-specific products)
        $businessUnitId = $request->input('business_unit_id');
        if ($businessUnitId) {
            $query->where('business_unit_id', $businessUnitId);
        }

        // Filter by Czerlop tagging status (barcode-qty readiness): untagged | tagged
        if ($request->filled('czerlop')) {
            if ($request->czerlop === 'untagged') {
                $query->whereNull('czerlop_product_id');
            } elseif ($request->czerlop === 'tagged') {
                $query->whereNotNull('czerlop_product_id');
            }
        }

        $products = $query->orderBy('title')->paginate(20);

        // Czerlop coverage summary (barcode-scan readiness). "Sellable" = active + listed.
        // Scoped only by business unit so the headline number is stable across other filters.
        $czerlopBase = ProductModel::where('status', 'active')->where('is_active', 1);
        if ($businessUnitId) {
            $czerlopBase->where('business_unit_id', $businessUnitId);
        }
        $czerlopSellableTotal = (clone $czerlopBase)->count();
        $czerlopUntaggedCount = (clone $czerlopBase)->whereNull('czerlop_product_id')->count();
        $czerlopCoverage = [
            'sellable_total' => $czerlopSellableTotal,
            'untagged' => $czerlopUntaggedCount,
            'tagged' => $czerlopSellableTotal - $czerlopUntaggedCount,
        ];

        // Get filter options based on CURRENT filters (cascading/dependent filters)
        // For each filter dropdown, we build a query that applies all OTHER active filters
        // This ensures each dropdown only shows values that actually exist for the filtered results
        
        // Base query for filter options (reusable)
        $baseFilterQuery = ProductModel::query();
        
        // Apply business_unit_id filter to base query so all filter option queries inherit it
        if ($businessUnitId) {
            $baseFilterQuery->where('business_unit_id', $businessUnitId);
        }
        
        // Sync Status options - respect other filters
        $syncStatusQuery = clone $baseFilterQuery;
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $searchWords = array_filter(explode(' ', $search));
            $syncStatusQuery->where(function($q) use ($search, $searchWords) {
                if (count($searchWords) > 1) {
                    $q->where(function($titleQuery) use ($searchWords) {
                        foreach ($searchWords as $word) {
                            $titleQuery->where('title', 'LIKE', "%{$word}%");
                        }
                    });
                } else {
                    $q->where('title', 'LIKE', "%{$search}%");
                }
                $q->orWhere('vendor', 'LIKE', "%{$search}%")
                  ->orWhere('product_type', 'LIKE', "%{$search}%")
                  ->orWhereHas('variants', function($vq) use ($search) {
                      $vq->where('sku', 'LIKE', "%{$search}%")->orWhere('title', 'LIKE', "%{$search}%");
                  });
            });
        }
        if ($request->has('status') && $request->status) $syncStatusQuery->where('status', $request->status);
        if ($request->has('product_type') && $request->product_type) $syncStatusQuery->where('product_type', $request->product_type);
        if ($request->has('vendor') && $request->vendor) $syncStatusQuery->where('vendor', $request->vendor);
        if ($request->has('attribute_1') && $request->attribute_1) $syncStatusQuery->where('attribute_1', $request->attribute_1);
        if ($request->has('attribute_2') && $request->attribute_2) $syncStatusQuery->where('attribute_2', $request->attribute_2);
        if ($request->has('attribute_3') && $request->attribute_3) $syncStatusQuery->where('attribute_3', $request->attribute_3);
        $syncStatuses = $syncStatusQuery->distinct()->pluck('sync_status')->filter()->sort();
        
        // Product Types (Category) options - respect other filters
        $productTypeQuery = clone $baseFilterQuery;
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $searchWords = array_filter(explode(' ', $search));
            $productTypeQuery->where(function($q) use ($search, $searchWords) {
                if (count($searchWords) > 1) {
                    $q->where(function($titleQuery) use ($searchWords) {
                        foreach ($searchWords as $word) {
                            $titleQuery->where('title', 'LIKE', "%{$word}%");
                        }
                    });
                } else {
                    $q->where('title', 'LIKE', "%{$search}%");
                }
                $q->orWhere('vendor', 'LIKE', "%{$search}%")
                  ->orWhere('product_type', 'LIKE', "%{$search}%")
                  ->orWhereHas('variants', function($vq) use ($search) {
                      $vq->where('sku', 'LIKE', "%{$search}%")->orWhere('title', 'LIKE', "%{$search}%");
                  });
            });
        }
        if ($request->has('status') && $request->status) $productTypeQuery->where('status', $request->status);
        if ($request->has('vendor') && $request->vendor) $productTypeQuery->where('vendor', $request->vendor);
        if ($request->has('sync_status') && $request->sync_status) $productTypeQuery->where('sync_status', $request->sync_status);
        if ($request->has('attribute_1') && $request->attribute_1) $productTypeQuery->where('attribute_1', $request->attribute_1);
        if ($request->has('attribute_2') && $request->attribute_2) $productTypeQuery->where('attribute_2', $request->attribute_2);
        if ($request->has('attribute_3') && $request->attribute_3) $productTypeQuery->where('attribute_3', $request->attribute_3);
        $productTypes = $productTypeQuery->distinct()->pluck('product_type')->filter()->sort();
        
        // Vendors options - respect other filters
        $vendorQuery = clone $baseFilterQuery;
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $searchWords = array_filter(explode(' ', $search));
            $vendorQuery->where(function($q) use ($search, $searchWords) {
                if (count($searchWords) > 1) {
                    $q->where(function($titleQuery) use ($searchWords) {
                        foreach ($searchWords as $word) {
                            $titleQuery->where('title', 'LIKE', "%{$word}%");
                        }
                    });
                } else {
                    $q->where('title', 'LIKE', "%{$search}%");
                }
                $q->orWhere('vendor', 'LIKE', "%{$search}%")
                  ->orWhere('product_type', 'LIKE', "%{$search}%")
                  ->orWhereHas('variants', function($vq) use ($search) {
                      $vq->where('sku', 'LIKE', "%{$search}%")->orWhere('title', 'LIKE', "%{$search}%");
                  });
            });
        }
        if ($request->has('status') && $request->status) $vendorQuery->where('status', $request->status);
        if ($request->has('product_type') && $request->product_type) $vendorQuery->where('product_type', $request->product_type);
        if ($request->has('sync_status') && $request->sync_status) $vendorQuery->where('sync_status', $request->sync_status);
        if ($request->has('attribute_1') && $request->attribute_1) $vendorQuery->where('attribute_1', $request->attribute_1);
        if ($request->has('attribute_2') && $request->attribute_2) $vendorQuery->where('attribute_2', $request->attribute_2);
        if ($request->has('attribute_3') && $request->attribute_3) $vendorQuery->where('attribute_3', $request->attribute_3);
        $vendors = $vendorQuery->distinct()->pluck('vendor')->filter()->sort();
        
        // Attribute 1 options - respect other filters
        $attr1Query = clone $baseFilterQuery;
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $searchWords = array_filter(explode(' ', $search));
            $attr1Query->where(function($q) use ($search, $searchWords) {
                if (count($searchWords) > 1) {
                    $q->where(function($titleQuery) use ($searchWords) {
                        foreach ($searchWords as $word) {
                            $titleQuery->where('title', 'LIKE', "%{$word}%");
                        }
                    });
                } else {
                    $q->where('title', 'LIKE', "%{$search}%");
                }
                $q->orWhere('vendor', 'LIKE', "%{$search}%")
                  ->orWhere('product_type', 'LIKE', "%{$search}%")
                  ->orWhereHas('variants', function($vq) use ($search) {
                      $vq->where('sku', 'LIKE', "%{$search}%")->orWhere('title', 'LIKE', "%{$search}%");
                  });
            });
        }
        if ($request->has('status') && $request->status) $attr1Query->where('status', $request->status);
        if ($request->has('product_type') && $request->product_type) $attr1Query->where('product_type', $request->product_type);
        if ($request->has('vendor') && $request->vendor) $attr1Query->where('vendor', $request->vendor);
        if ($request->has('sync_status') && $request->sync_status) $attr1Query->where('sync_status', $request->sync_status);
        if ($request->has('attribute_2') && $request->attribute_2) $attr1Query->where('attribute_2', $request->attribute_2);
        if ($request->has('attribute_3') && $request->attribute_3) $attr1Query->where('attribute_3', $request->attribute_3);
        $attribute1s = $attr1Query->distinct()->pluck('attribute_1')->filter()->sort();
        
        // Attribute 2 options - respect other filters
        $attr2Query = clone $baseFilterQuery;
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $searchWords = array_filter(explode(' ', $search));
            $attr2Query->where(function($q) use ($search, $searchWords) {
                if (count($searchWords) > 1) {
                    $q->where(function($titleQuery) use ($searchWords) {
                        foreach ($searchWords as $word) {
                            $titleQuery->where('title', 'LIKE', "%{$word}%");
                        }
                    });
                } else {
                    $q->where('title', 'LIKE', "%{$search}%");
                }
                $q->orWhere('vendor', 'LIKE', "%{$search}%")
                  ->orWhere('product_type', 'LIKE', "%{$search}%")
                  ->orWhereHas('variants', function($vq) use ($search) {
                      $vq->where('sku', 'LIKE', "%{$search}%")->orWhere('title', 'LIKE', "%{$search}%");
                  });
            });
        }
        if ($request->has('status') && $request->status) $attr2Query->where('status', $request->status);
        if ($request->has('product_type') && $request->product_type) $attr2Query->where('product_type', $request->product_type);
        if ($request->has('vendor') && $request->vendor) $attr2Query->where('vendor', $request->vendor);
        if ($request->has('sync_status') && $request->sync_status) $attr2Query->where('sync_status', $request->sync_status);
        if ($request->has('attribute_1') && $request->attribute_1) $attr2Query->where('attribute_1', $request->attribute_1);
        if ($request->has('attribute_3') && $request->attribute_3) $attr2Query->where('attribute_3', $request->attribute_3);
        $attribute2s = $attr2Query->distinct()->pluck('attribute_2')->filter()->sort();
        
        // Attribute 3 options - respect other filters
        $attr3Query = clone $baseFilterQuery;
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $searchWords = array_filter(explode(' ', $search));
            $attr3Query->where(function($q) use ($search, $searchWords) {
                if (count($searchWords) > 1) {
                    $q->where(function($titleQuery) use ($searchWords) {
                        foreach ($searchWords as $word) {
                            $titleQuery->where('title', 'LIKE', "%{$word}%");
                        }
                    });
                } else {
                    $q->where('title', 'LIKE', "%{$search}%");
                }
                $q->orWhere('vendor', 'LIKE', "%{$search}%")
                  ->orWhere('product_type', 'LIKE', "%{$search}%")
                  ->orWhereHas('variants', function($vq) use ($search) {
                      $vq->where('sku', 'LIKE', "%{$search}%")->orWhere('title', 'LIKE', "%{$search}%");
                  });
            });
        }
        if ($request->has('status') && $request->status) $attr3Query->where('status', $request->status);
        if ($request->has('product_type') && $request->product_type) $attr3Query->where('product_type', $request->product_type);
        if ($request->has('vendor') && $request->vendor) $attr3Query->where('vendor', $request->vendor);
        if ($request->has('sync_status') && $request->sync_status) $attr3Query->where('sync_status', $request->sync_status);
        if ($request->has('attribute_1') && $request->attribute_1) $attr3Query->where('attribute_1', $request->attribute_1);
        if ($request->has('attribute_2') && $request->attribute_2) $attr3Query->where('attribute_2', $request->attribute_2);
        $attribute3s = $attr3Query->distinct()->pluck('attribute_3')->filter()->sort();

        // If this is an AJAX request, return JSON
        if ($request->wantsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
            return response()->json([
                'success' => true,
                'products' => $products->items(),
                'pagination' => [
                    'current_page' => $products->currentPage(),
                    'from' => $products->firstItem(),
                    'to' => $products->lastItem(),
                    'total' => $products->total(),
                    'per_page' => $products->perPage(),
                    'last_page' => $products->lastPage()
                ],
                'filter_options' => [
                    'product_types' => $productTypes->values()->toArray(),
                    'vendors' => $vendors->values()->toArray(),
                    'attribute_1s' => $attribute1s->values()->toArray(),
                    'attribute_2s' => $attribute2s->values()->toArray(),
                    'attribute_3s' => $attribute3s->values()->toArray(),
                    'sync_statuses' => $syncStatuses->values()->toArray()
                ],
                'czerlop_coverage' => $czerlopCoverage,
            ]);
        }

        // Get attribute labels for display
        $attributeLabels = $this->readAttributeLabels();

        return view('pages.products.index', compact('products', 'syncStatuses', 'productTypes', 'vendors', 'attribute1s', 'attribute2s', 'attribute3s', 'attributeLabels', 'czerlopCoverage'));
    }

    /**
     * Bulk adjust variant prices by filter and mode
     */
    /**
     * Preview bulk price adjustments without applying them
     */
    public function previewBulkAdjustPrices(Request $request)
    {
        $validated = $request->validate([
            'mode' => 'required_unless:operation,set|in:percent,fixed',
            'operation' => 'required|in:increase,decrease,set',
            'amount' => 'required|numeric|min:0',
            // Optional filters
            'product_type' => 'nullable|string',
            'vendor' => 'nullable|string',
            'attribute_1' => 'nullable|string',
            'attribute_2' => 'nullable|string',
            'attribute_3' => 'nullable|string',
            'search' => 'nullable|string',
            'status' => 'nullable|string',
            // Optional: specific product IDs (for bulk selection from table)
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'integer',
        ]);

        $query = ProductModel::query();
        
        // If specific product IDs are provided, use those instead of filters
        if (!empty($validated['product_ids'])) {
            $query->whereIn('id', $validated['product_ids']);
        } else {
            // Use category filters
            foreach (['product_type','vendor','attribute_1','attribute_2','attribute_3'] as $f) {
                if ($request->$f) $query->where($f, $request->$f);
            }
            
            // Handle search filter (same logic as index method)
            if ($request->search) {
                $search = $request->search;
                $searchWords = array_filter(explode(' ', $search));
                
                $query->where(function($q) use ($search, $searchWords) {
                    if (count($searchWords) > 1) {
                        $q->where(function($titleQuery) use ($searchWords) {
                            foreach ($searchWords as $word) {
                                $titleQuery->where('title', 'LIKE', "%{$word}%");
                            }
                        });
                    } else {
                        $q->where('title', 'LIKE', "%{$search}%");
                    }
                    $q->orWhere('vendor', 'LIKE', "%{$search}%")
                      ->orWhere('product_type', 'LIKE', "%{$search}%")
                      ->orWhereHas('variants', function($vq) use ($search) {
                          $vq->where('sku', 'LIKE', "%{$search}%")
                            ->orWhere('title', 'LIKE', "%{$search}%");
                      });
                });
            }
            
            // Handle status filter
            if ($request->status) {
                $query->where('status', $request->status);
            }
        }

        $products = $query->with('variants')->get();
        $changes = []; // Preview changes WITHOUT saving

        foreach ($products as $product) {
            // 📦 Bundles are skipped by the real run too (their price = Σ packet prices).
            if (\App\Services\CRM\ProductBundle::isBundle($product)) {
                continue;
            }
            foreach ($product->variants as $variant) {
                $old = (float) $variant->price;
                $new = $old;

                if ($validated['operation'] === 'set') {
                    // Set exact price
                    $new = (float) $validated['amount'];
                } elseif ($validated['mode'] === 'percent') {
                    $delta = $old * ($validated['amount'] / 100);
                    $new = $validated['operation'] === 'increase' ? $old + $delta : $old - $delta;
                } else {
                    $new = $validated['operation'] === 'increase' ? $old + $validated['amount'] : $old - $validated['amount'];
                }

                // Guardrails: never below zero
                $new = max(0, round($new, 2));

                if ($new !== $old) {
                    // Just record the change, don't save
                    $changes[] = [
                        'product_title' => $product->title,
                        'variant_title' => $variant->title,
                        'sku' => $variant->sku,
                        'old_price' => $old,
                        'new_price' => $new,
                        'difference' => $new - $old,
                        'difference_percent' => $old > 0 ? round((($new - $old) / $old) * 100, 2) : 0
                    ];
                }
            }
        }

        $affectedVariants = count($changes);
        $affectedProducts = $products->filter(function($product) use ($changes) {
            return collect($changes)->contains(function($change) use ($product) {
                return $change['product_title'] === $product->title;
            });
        })->count();

        $message = "Will update {$affectedProducts} products ({$affectedVariants} variants)";

        return response()->json([
            'success' => true,
            'preview' => true,
            'affected_variants' => $affectedVariants,
            'affected_products' => $affectedProducts,
            'message' => $message,
            'changes' => $changes
        ]);
    }

    public function bulkAdjustPrices(Request $request)
    {
        $validated = $request->validate([
            'mode' => 'required_unless:operation,set|in:percent,fixed',
            'operation' => 'required|in:increase,decrease,set',
            'amount' => 'required|numeric|min:0',
            // Optional filters
            'product_type' => 'nullable|string',
            'vendor' => 'nullable|string',
            'attribute_1' => 'nullable|string',
            'attribute_2' => 'nullable|string',
            'attribute_3' => 'nullable|string',
            'search' => 'nullable|string',
            'status' => 'nullable|string',
            // Optional: specific product IDs (for bulk selection from table)
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'integer',
        ]);

        $query = ProductModel::query();
        
        // If specific product IDs are provided, use those instead of filters
        if (!empty($validated['product_ids'])) {
            $query->whereIn('id', $validated['product_ids']);
        } else {
            // Use category filters
            foreach (['product_type','vendor','attribute_1','attribute_2','attribute_3'] as $f) {
                if ($request->$f) $query->where($f, $request->$f);
            }
            
            // Handle search filter (same logic as index method)
            if ($request->search) {
                $search = $request->search;
                $searchWords = array_filter(explode(' ', $search));
                
                $query->where(function($q) use ($search, $searchWords) {
                    if (count($searchWords) > 1) {
                        $q->where(function($titleQuery) use ($searchWords) {
                            foreach ($searchWords as $word) {
                                $titleQuery->where('title', 'LIKE', "%{$word}%");
                            }
                        });
                    } else {
                        $q->where('title', 'LIKE', "%{$search}%");
                    }
                    $q->orWhere('vendor', 'LIKE', "%{$search}%")
                      ->orWhere('product_type', 'LIKE', "%{$search}%")
                      ->orWhereHas('variants', function($vq) use ($search) {
                          $vq->where('sku', 'LIKE', "%{$search}%")
                            ->orWhere('title', 'LIKE', "%{$search}%");
                      });
                });
            }
            
            // Handle status filter
            if ($request->status) {
                $query->where('status', $request->status);
            }
        }

        $products = $query->with('variants')->get();
        $affectedVariants = 0;
        $affectedProducts = 0;
        $changes = []; // Track all price changes for detailed summary

        foreach ($products as $product) {
            // 📦 A bundle's price is the total of its packet prices — never bulk-adjusted.
            if (\App\Services\CRM\ProductBundle::isBundle($product)) {
                continue;
            }
            $productUpdated = false;
            foreach ($product->variants as $variant) {
                $old = (float) $variant->price;
                $new = $old;

                if ($validated['operation'] === 'set') {
                    // Set exact price
                    $new = (float) $validated['amount'];
                } elseif ($validated['mode'] === 'percent') {
                    $delta = $old * ($validated['amount'] / 100);
                    $new = $validated['operation'] === 'increase' ? $old + $delta : $old - $delta;
                } else {
                    $new = $validated['operation'] === 'increase' ? $old + $validated['amount'] : $old - $validated['amount'];
                }

                // Guardrails: never below zero
                $new = max(0, round($new, 2));

                if ($new !== $old) {
                    $variant->price = $new;
                    $variant->save();
                    $affectedVariants++;
                    $productUpdated = true;
                    
                    // ⭐ Log price change
                    \App\Models\CRM\ProductChangeHistory::logChange([
                        'product_id' => $product->id,
                        'variant_id' => $variant->id,
                        'change_type' => \App\Models\CRM\ProductChangeHistory::TYPE_PRICE_CHANGE,
                        'field_name' => 'Price (Bulk Adjust)',
                        'old_value' => (string)$old,
                        'new_value' => (string)$new,
                        'change_source' => 'web',
                        'notes' => "Bulk price {$validated['operation']} operation",
                    ]);
                    
                    // Record this change for the summary
                    $changes[] = [
                        'product_title' => $product->title,
                        'variant_title' => $variant->title,
                        'sku' => $variant->sku,
                        'old_price' => $old,
                        'new_price' => $new,
                        'difference' => $new - $old,
                        'difference_percent' => $old > 0 ? round((($new - $old) / $old) * 100, 2) : 0
                    ];
                }
            }

            if ($productUpdated) {
                $affectedProducts++;
                // Update cached price range on product
                $prices = $product->variants()->pluck('price')->toArray();
                if (!empty($prices)) {
                    $product->price_min = min($prices);
                    $product->price_max = max($prices);
                    $product->save();
                }
            }
        }

        $message = "Updated {$affectedProducts} products ({$affectedVariants} variants)";
        if ($affectedProducts !== $affectedVariants) {
            $message .= " - Some products have multiple variants";
        }

        return response()->json([
            'success' => true,
            'affected_variants' => $affectedVariants,
            'affected_products' => $affectedProducts,
            'message' => $message,
            'changes' => $changes // Return detailed changes for frontend display
        ]);
    }

    /**
     * Quick update a single variant's price (for inline editing)
     */
    public function quickUpdatePrice(Request $request)
    {
        try {
            $validated = $request->validate([
                'variant_id' => 'required|integer|exists:t_crm_prod_product_variant,id',
                'product_id' => 'required|integer|exists:t_crm_prod_product,id',
                'price' => 'required|numeric|min:0',
            ]);

            // Get the variant
            $variant = \App\Models\CRM\ProductVariantModel::findOrFail($validated['variant_id']);
            
            // Verify variant belongs to the product
            if ($variant->product_id != $validated['product_id']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Variant does not belong to the specified product'
                ], 400);
            }

            $oldPrice = (float) $variant->price;
            $newPrice = round((float) $validated['price'], 2);

            // Update variant price
            $variant->price = $newPrice;
            $variant->save();

            // Update product's cached price range
            $product = ProductModel::findOrFail($validated['product_id']);
            $prices = $product->variants()->pluck('price')->toArray();
            if (!empty($prices)) {
                $product->price_min = min($prices);
                $product->price_max = max($prices);
                $product->save();
            }

            // ⭐ Log price change to history
            if (abs($oldPrice - $newPrice) >= 0.01) {
                \App\Models\CRM\ProductChangeHistory::logChange([
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'change_type' => \App\Models\CRM\ProductChangeHistory::TYPE_PRICE_CHANGE,
                    'field_name' => 'Price',
                    'old_value' => (string)$oldPrice,
                    'new_value' => (string)$newPrice,
                    'change_source' => 'web',
                    'notes' => 'Quick inline price edit',
                ]);
            }

            Log::info('Quick price update', [
                'variant_id' => $variant->id,
                'product_id' => $product->id,
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Price updated successfully',
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
                'price_min' => $product->price_min,
                'price_max' => $product->price_max
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Quick price update error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update price: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get category summary for selected products (bulk category assignment)
     */
    public function bulkCategorySummary(Request $request)
    {
        try {
            $query = ProductModel::query();
            
            // If specific product IDs are provided, use those
            if ($request->has('product_ids') && is_array($request->product_ids) && !empty($request->product_ids)) {
                $query->whereIn('id', $request->product_ids);
            } else {
                // Use filters
                foreach (['product_type', 'vendor', 'attribute_1', 'attribute_2', 'attribute_3'] as $f) {
                    if ($request->$f) {
                        $query->where($f, $request->$f);
                    }
                }
                
                // Handle search filter
                if ($request->search) {
                    $search = $request->search;
                    $query->where(function($q) use ($search) {
                        $q->where('title', 'like', "%{$search}%")
                          ->orWhere('shopify_handle', 'like', "%{$search}%")
                          ->orWhereHas('variants', function($vq) use ($search) {
                              $vq->where('sku', 'like', "%{$search}%");
                          });
                    });
                }
                
                // Handle status filter
                if ($request->status) {
                    $query->where('status', $request->status);
                }
            }
            
            $products = $query->get(['id', 'attribute_1', 'attribute_2', 'attribute_3']);
            
            // Build category distribution
            $attribute1Distribution = [];
            $attribute2Distribution = [];
            $attribute3Distribution = [];
            
            foreach ($products as $product) {
                $attr1 = $product->attribute_1 ?: '';
                $attr2 = $product->attribute_2 ?: '';
                $attr3 = $product->attribute_3 ?: '';
                
                $attribute1Distribution[$attr1] = ($attribute1Distribution[$attr1] ?? 0) + 1;
                $attribute2Distribution[$attr2] = ($attribute2Distribution[$attr2] ?? 0) + 1;
                $attribute3Distribution[$attr3] = ($attribute3Distribution[$attr3] ?? 0) + 1;
            }
            
            // Sort by count descending
            arsort($attribute1Distribution);
            arsort($attribute2Distribution);
            arsort($attribute3Distribution);
            
            return response()->json([
                'success' => true,
                'total_products' => $products->count(),
                'attribute_1' => $attribute1Distribution,
                'attribute_2' => $attribute2Distribution,
                'attribute_3' => $attribute3Distribution
            ]);
            
        } catch (\Exception $e) {
            Log::error('Bulk category summary error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load category summary: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Preview bulk category changes
     */
    public function bulkCategoryPreview(Request $request)
    {
        try {
            $query = ProductModel::query();
            
            // If specific product IDs are provided, use those
            if ($request->has('product_ids') && is_array($request->product_ids) && !empty($request->product_ids)) {
                $query->whereIn('id', $request->product_ids);
            } else {
                // Use filters
                foreach (['product_type', 'vendor', 'attribute_1', 'attribute_2', 'attribute_3'] as $f) {
                    // Skip category attributes from filters since we're changing them
                    if (in_array($f, ['attribute_1', 'attribute_2', 'attribute_3'])) {
                        // Only use as filter if it's coming from the original filter, not the new value
                        if ($request->has('filter_' . $f) && $request->{'filter_' . $f}) {
                            $query->where($f, $request->{'filter_' . $f});
                        }
                    } else {
                        if ($request->$f) {
                            $query->where($f, $request->$f);
                        }
                    }
                }
                
                // Handle search filter
                if ($request->search) {
                    $search = $request->search;
                    $query->where(function($q) use ($search) {
                        $q->where('title', 'like', "%{$search}%")
                          ->orWhere('shopify_handle', 'like', "%{$search}%")
                          ->orWhereHas('variants', function($vq) use ($search) {
                              $vq->where('sku', 'like', "%{$search}%");
                          });
                    });
                }
                
                // Handle status filter
                if ($request->status) {
                    $query->where('status', $request->status);
                }
            }
            
            $products = $query->with(['variants' => function($q) {
                $q->select('id', 'product_id', 'sku')->limit(1);
            }])->get(['id', 'title', 'attribute_1', 'attribute_2', 'attribute_3']);
            
            // Determine which changes to apply
            $changes = [];
            $newAttr1 = $request->attribute_1;
            $newAttr2 = $request->attribute_2;
            $newAttr3 = $request->attribute_3;
            
            // Only include changes that are explicitly set (not empty string which means "keep existing")
            if ($newAttr1 !== null && $newAttr1 !== '') {
                $changes['attribute_1'] = $newAttr1 === '__clear__' ? null : $newAttr1;
            }
            if ($newAttr2 !== null && $newAttr2 !== '') {
                $changes['attribute_2'] = $newAttr2 === '__clear__' ? null : $newAttr2;
            }
            if ($newAttr3 !== null && $newAttr3 !== '') {
                $changes['attribute_3'] = $newAttr3 === '__clear__' ? null : $newAttr3;
            }
            
            if (empty($changes)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No changes selected. Please select at least one category to update.'
                ], 400);
            }
            
            // Build sample products list (limit to 20)
            $sampleProducts = $products->take(20)->map(function($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->title,
                    'sku' => $product->variants->first()?->sku ?? null,
                    'attribute_1' => $product->attribute_1,
                    'attribute_2' => $product->attribute_2,
                    'attribute_3' => $product->attribute_3
                ];
            })->toArray();
            
            return response()->json([
                'success' => true,
                'affected_count' => $products->count(),
                'changes' => $changes,
                'sample_products' => $sampleProducts
            ]);
            
        } catch (\Exception $e) {
            Log::error('Bulk category preview error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate preview: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Apply bulk category changes
     */
    public function bulkCategoryApply(Request $request)
    {
        try {
            $query = ProductModel::query();
            
            // If specific product IDs are provided, use those
            if ($request->has('product_ids') && is_array($request->product_ids) && !empty($request->product_ids)) {
                $query->whereIn('id', $request->product_ids);
            } else {
                // Use filters
                foreach (['product_type', 'vendor'] as $f) {
                    if ($request->$f) {
                        $query->where($f, $request->$f);
                    }
                }
                
                // Handle search filter
                if ($request->search) {
                    $search = $request->search;
                    $query->where(function($q) use ($search) {
                        $q->where('title', 'like', "%{$search}%")
                          ->orWhere('shopify_handle', 'like', "%{$search}%")
                          ->orWhereHas('variants', function($vq) use ($search) {
                              $vq->where('sku', 'like', "%{$search}%");
                          });
                    });
                }
                
                // Handle status filter
                if ($request->status) {
                    $query->where('status', $request->status);
                }
            }
            
            // Determine which changes to apply
            $updates = [];
            $newAttr1 = $request->attribute_1;
            $newAttr2 = $request->attribute_2;
            $newAttr3 = $request->attribute_3;
            
            // Only include changes that are explicitly set (not empty string which means "keep existing")
            if ($newAttr1 !== null && $newAttr1 !== '') {
                $updates['attribute_1'] = $newAttr1 === '__clear__' ? null : $newAttr1;
            }
            if ($newAttr2 !== null && $newAttr2 !== '') {
                $updates['attribute_2'] = $newAttr2 === '__clear__' ? null : $newAttr2;
            }
            if ($newAttr3 !== null && $newAttr3 !== '') {
                $updates['attribute_3'] = $newAttr3 === '__clear__' ? null : $newAttr3;
            }
            
            if (empty($updates)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No changes selected. Please select at least one category to update.'
                ], 400);
            }
            
            // Get products before update to log changes
            $products = $query->get(['id', 'title', 'attribute_1', 'attribute_2', 'attribute_3']);
            $affectedCount = $products->count();
            
            // Log individual changes for each product
            foreach ($products as $product) {
                foreach ($updates as $field => $newValue) {
                    $oldValue = $product->{$field};
                    if ($oldValue !== $newValue) {
                        $levelNum = str_replace('attribute_', '', $field);
                        \App\Models\CRM\ProductChangeHistory::logChange([
                            'product_id' => $product->id,
                            'change_type' => \App\Models\CRM\ProductChangeHistory::TYPE_CATEGORY_CHANGE,
                            'field_name' => "Category Level {$levelNum} (Bulk)",
                            'old_value' => $oldValue,
                            'new_value' => $newValue,
                            'change_source' => 'web',
                            'notes' => 'Bulk category update',
                        ]);
                    }
                }
            }
            
            // Apply the updates
            $query = ProductModel::query();
            if ($request->has('product_ids') && is_array($request->product_ids) && !empty($request->product_ids)) {
                $query->whereIn('id', $request->product_ids);
            } else {
                foreach (['product_type', 'vendor'] as $f) {
                    if ($request->$f) {
                        $query->where($f, $request->$f);
                    }
                }
                if ($request->search) {
                    $search = $request->search;
                    $query->where(function($q) use ($search) {
                        $q->where('title', 'like', "%{$search}%")
                          ->orWhere('shopify_handle', 'like', "%{$search}%")
                          ->orWhereHas('variants', function($vq) use ($search) {
                              $vq->where('sku', 'like', "%{$search}%");
                          });
                    });
                }
                if ($request->status) {
                    $query->where('status', $request->status);
                }
            }
            $query->update($updates);
            
            Log::info('Bulk category update applied', [
                'affected_count' => $affectedCount,
                'updates' => $updates,
                'user_id' => auth()->id(),
                'filters' => $request->only(['product_type', 'vendor', 'search', 'status', 'product_ids'])
            ]);
            
            return response()->json([
                'success' => true,
                'affected_count' => $affectedCount,
                'message' => "Successfully updated categories for {$affectedCount} products"
            ]);
            
        } catch (\Exception $e) {
            Log::error('Bulk category apply error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to apply changes: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get weight factors for products by ID or name (for invoice editing)
     * Priority: product_id (reliable) > name matching (fallback)
     */
    public function getWeightFactors(Request $request)
    {
        $validated = $request->validate([
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'nullable|integer',
            'product_names' => 'nullable|array',
            'product_names.*' => 'string'
        ]);
        
        $productIds = $validated['product_ids'] ?? [];
        $productNames = $validated['product_names'] ?? [];
        $weightFactors = [];
        $weightFactorsByProductId = [];
        
        // ⭐ PRIMARY: Get weight factors by product_id (most reliable)
        if (!empty($productIds)) {
            $validIds = array_filter($productIds, fn($id) => !empty($id) && is_numeric($id));
            if (!empty($validIds)) {
                $productsById = ProductModel::whereIn('id', $validIds)
                    ->select('id', 'title', 'weight_factor')
                    ->get();
                
                foreach ($productsById as $product) {
                    $weightFactorsByProductId[$product->id] = floatval($product->weight_factor ?? 1.0);
                    // Also add by title for cross-reference
                    $weightFactors[$product->title] = floatval($product->weight_factor ?? 1.0);
                }
            }
        }
        
        // ⭐ FALLBACK: Get weight factors by product name (for items without product_id)
        if (!empty($productNames)) {
            // Filter out names we already have from product_id lookup
            $namesToLookup = array_filter($productNames, fn($name) => !isset($weightFactors[$name]));
            
            if (!empty($namesToLookup)) {
                $productsByName = ProductModel::whereIn('title', $namesToLookup)
                    ->select('id', 'title', 'weight_factor')
                    ->get();
                
                foreach ($productsByName as $product) {
                    $weightFactors[$product->title] = floatval($product->weight_factor ?? 1.0);
                    $weightFactorsByProductId[$product->id] = floatval($product->weight_factor ?? 1.0);
                }
            }
            
            // Default to 1 for products not found
            foreach ($productNames as $name) {
                if (!isset($weightFactors[$name])) {
                    $weightFactors[$name] = 1.0;
                }
            }
        }
        
        return response()->json([
            'success' => true,
            'weight_factors' => $weightFactors,
            'weight_factors_by_id' => $weightFactorsByProductId
        ]);
    }

    /**
     * Bulk set weight factor for products
     */
    public function bulkSetWeightFactor(Request $request)
    {
        $validated = $request->validate([
            'weight_factor' => 'required|numeric|min:0.01',
            'exclude_lean' => 'nullable|boolean',
            // Optional filters
            'product_type' => 'nullable|string',
            'vendor' => 'nullable|string',
            'attribute_1' => 'nullable|string',
            'attribute_2' => 'nullable|string',
            'attribute_3' => 'nullable|string',
        ]);

        $query = ProductModel::query();
        
        // Apply category filters
        foreach (['product_type','vendor','attribute_1','attribute_2','attribute_3'] as $f) {
            if ($request->$f) $query->where($f, $request->$f);
        }

        // Exclude lean products if requested
        if ($validated['exclude_lean'] ?? false) {
            $query->where(function($q) {
                $q->where('is_lean', 0)
                  ->orWhereNull('is_lean');
            });
            // Also exclude products with "lean" in the name
            $query->where('title', 'NOT LIKE', '%lean%');
        }

        $products = $query->get();
        $affectedProducts = $products->count();

        // Update all matching products
        foreach ($products as $product) {
            $product->weight_factor = $validated['weight_factor'];
            $product->save();
        }

        $message = "Updated weight factor to {$validated['weight_factor']} for {$affectedProducts} products";
        if ($validated['exclude_lean'] ?? false) {
            $message .= " (lean products excluded)";
        }

        return response()->json([
            'success' => true,
            'affected_products' => $affectedProducts,
            'message' => $message
        ]);
    }

    public function show($id)
    {
        try {
            $product = ProductModel::with(['variants', 'businessUnit'])->findOrFail($id);
            
            return response()->json([
                'success' => true,
                'product' => $product,
                'can_delete' => $this->userCanDeleteProducts(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }
    }

    /**
     * Get dropdown options for product creation/editing (mobile API)
     */
    public function getDropdownOptions(Request $request)
    {
        try {
            $baseQuery = ProductModel::query();
            
            // Filter by business_unit_id if provided (used by Khaas Mode)
            $businessUnitId = $request->input('business_unit_id');
            if ($businessUnitId) {
                $baseQuery->where('business_unit_id', $businessUnitId);
            }
            
            $productTypes = (clone $baseQuery)->distinct()->pluck('product_type')->filter()->sort()->values();
            $vendors = (clone $baseQuery)->distinct()->pluck('vendor')->filter()->sort()->values();
            $attribute1s = (clone $baseQuery)->distinct()->pluck('attribute_1')->filter()->sort()->values();
            $attribute2s = (clone $baseQuery)->distinct()->pluck('attribute_2')->filter()->sort()->values();
            $attribute3s = (clone $baseQuery)->distinct()->pluck('attribute_3')->filter()->sort()->values();
            
            return response()->json([
                'success' => true,
                'options' => [
                    'product_types' => $productTypes,
                    'vendors' => $vendors,
                    'attribute_1s' => $attribute1s,
                    'attribute_2s' => $attribute2s,
                    'attribute_3s' => $attribute3s,
                ],
                'labels' => [
                    'attribute_1' => 'Category Level 1',
                    'attribute_2' => 'Category Level 2',
                    'attribute_3' => 'Category Level 3',
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get dropdown options'
            ], 500);
        }
    }

    public function search(Request $request)
    {
        try {
            $query = $request->get('q', '');
            $limit = $request->get('limit', 10);
            
            // Split search query into individual words for flexible matching
            $searchWords = array_filter(explode(' ', $query));
            
            $productsQuery = \App\Models\CRM\ProductModel::with('variants')
                ->where('is_active', true);

            // Qurbani filter: only show qurbani-category products
            if ($request->get('qurbani')) {
                $productsQuery->whereRaw("LOWER(attribute_1) = 'qurbani'");
            }

            $products = $productsQuery->where(function($q) use ($query, $searchWords) {
                    // Create hyphen-less version for flexible SKU search
                    $queryNoHyphens = str_replace(['-', ' '], '', $query);
                    
                    // If multiple words, match each word independently in title (in any order)
                    if (count($searchWords) > 1) {
                        $q->where(function($titleQuery) use ($searchWords) {
                            foreach ($searchWords as $word) {
                                $titleQuery->where('title', 'LIKE', "%{$word}%");
                            }
                        });
                    } else {
                        // Single word search - keep original behavior
                        $q->where('title', 'LIKE', "%{$query}%");
                    }
                    
                    // Also search in variants
                    $q->orWhereHas('variants', function($vq) use ($query, $queryNoHyphens, $searchWords) {
                        if (count($searchWords) > 1) {
                            // Multi-word search in variant title
                            $vq->where(function($titleQuery) use ($searchWords) {
                                foreach ($searchWords as $word) {
                                    $titleQuery->where('title', 'LIKE', "%{$word}%");
                                }
                            });
                        } else {
                            $vq->where('title', 'LIKE', "%{$query}%");
                        }
                        
                        // SKU search (always use full query)
                        $vq->orWhere('sku', 'LIKE', "%{$query}%")
                            ->orWhereRaw('REPLACE(REPLACE(sku, "-", ""), " ", "") LIKE ?', ["%{$queryNoHyphens}%"]);
                      });
                })
                ->limit($limit)
                ->get();
            
            $results = [];
            foreach ($products as $product) {
                // 📦 Bundled product: tell the order forms what picking it REALLY adds
                // (the product inside × packets at this variant's price). null = ordinary.
                $isBundleProduct = \App\Services\CRM\ProductBundle::isBundle($product);
                // Only add variants, not the parent product (to avoid duplicates)
                foreach ($product->variants as $variant) {
                    if ($variant->available) {
                        $bundleInfo = null;
                        if ($isBundleProduct) {
                            $variant->setRelation('product', $product);
                            $bundleInfo = \App\Services\CRM\ProductBundle::searchInfo($variant);
                        }
                        // For single variants, use just the product title
                        // For multiple variants, append variant title if it's different
                        $displayName = $product->title;
                        if (count($product->variants) > 1 && $variant->title && $variant->title !== $product->title) {
                            $displayName .= ' - ' . $variant->title;
                        }
                        
                        $results[] = [
                            'id' => 'variant_' . $variant->id,
                            'type' => 'variant',
                            'name' => $displayName,
                            'sku' => $variant->sku,
                            'price' => $variant->price,
                            'inventory' => $variant->inventory_quantity,
                            'vendor' => $product->vendor,
                            'attribute_1' => $product->attribute_1,
                            'attribute_2' => $product->attribute_2,
                            'attribute_3' => $product->attribute_3,
                            'bundle' => $bundleInfo,
                        ];
                    }
                }
                
                // If no available variants, still show the product (but mark as unavailable)
                if ($product->variants->where('available', true)->count() === 0 && $product->variants->count() > 0) {
                    $firstVariant = $product->variants->first();
                    $results[] = [
                        'id' => 'variant_' . $firstVariant->id,
                        'type' => 'variant',
                        'name' => $product->title . ' (Out of Stock)',
                        'sku' => $firstVariant->sku,
                        'price' => $firstVariant->price,
                        'inventory' => 0,
                        'vendor' => $product->vendor,
                        'attribute_1' => $product->attribute_1,
                        'attribute_2' => $product->attribute_2,
                        'attribute_3' => $product->attribute_3,
                    ];
                }
            }
            
            return response()->json([
                'success' => true,
                'products' => $results,
                'debug' => [
                    'query' => $query,
                    'products_found' => $products->count(),
                    'results_count' => count($results)
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to search products: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Import products from Shopify (DISABLED)
     * 
     * ⚠️ SHOPIFY/WOOCOMMERCE INTEGRATION DISABLED
     * Products are now managed exclusively through web UI and mobile app.
     * This prevents accidental data overwrites and preserves SKU/category data.
     * 
     * Disabled on: December 2024
     */
    public function importProducts(Request $request)
    {
        Log::warning('Shopify product import attempted but is DISABLED', [
            'user_id' => auth()->id(),
            'ip' => $request->ip()
        ]);
        
        return response()->json([
            'success' => false,
            'message' => 'Product import from Shopify has been permanently disabled. Products are now managed exclusively through the web UI and mobile app to prevent data loss (SKU, categories).',
            'status' => 'disabled'
        ], 403);
    }

    /**
     * Sync single product from Shopify (DISABLED)
     * 
     * ⚠️ SHOPIFY SYNC DISABLED
     * Products are now managed exclusively through web UI and mobile app.
     * 
     * Disabled on: December 2024
     */
    public function syncProduct(Request $request, $id)
    {
        Log::warning('Shopify product sync attempted but is DISABLED', [
            'product_id' => $id,
            'user_id' => auth()->id(),
            'ip' => $request->ip()
        ]);
        
        return response()->json([
            'success' => false,
            'message' => 'Product sync with Shopify has been permanently disabled. Products are now managed exclusively through the web UI and mobile app.',
            'status' => 'disabled'
        ], 403);
    }

    /**
     * Import ALL products from Shopify (DISABLED)
     * 
     * ⚠️ BULK IMPORT DISABLED
     * Products are now managed exclusively through web UI and mobile app.
     * This prevents accidental data overwrites and preserves SKU/category data.
     * 
     * Disabled on: December 2024
     */
    public function importAllProducts(Request $request)
    {
        Log::warning('Bulk product import attempted but is DISABLED', [
            'source' => $request->get('source', 'Shopify'),
            'user_id' => auth()->id(),
            'ip' => $request->ip()
        ]);
        
        return response()->json([
            'success' => false,
            'message' => 'Bulk product import from Shopify/WooCommerce has been permanently disabled. Products are now managed exclusively through the web UI and mobile app to prevent data loss (SKU, categories).',
            'status' => 'disabled'
        ], 403);
    }

    /**
     * Show the form for creating a new product
     */
    public function create()
    {
        // Store the previous URL (with filters) in session for "Back to Products" button
        if (url()->previous() !== url()->current()) {
            $previousUrl = url()->previous();
            // Only store if it's from the products index page
            if (strpos($previousUrl, route('products.index')) !== false || strpos($previousUrl, '/products') !== false) {
                session(['products_return_url' => $previousUrl]);
            }
        }
        
        // Get distinct values for dropdowns (same as index page filters)
        $productTypes = ProductModel::distinct()->pluck('product_type')->filter()->sort()->values();
        $vendors = ProductModel::distinct()->pluck('vendor')->filter()->sort()->values();
        $attribute1s = ProductModel::distinct()->pluck('attribute_1')->filter()->sort()->values();
        $attribute2s = ProductModel::distinct()->pluck('attribute_2')->filter()->sort()->values();
        $attribute3s = ProductModel::distinct()->pluck('attribute_3')->filter()->sort()->values();
        
        // Get attribute labels
        $attributeLabels = [
            '1' => 'Category Level 1',
            '2' => 'Category Level 2',
            '3' => 'Category Level 3'
        ];
        
        // Get business units for dropdown
        $businessUnits = \App\Models\FIN\BusinessUnitModel::where('is_active', 1)->ordered()->get();
        
        return view('pages.products.create', compact(
            'productTypes',
            'vendors',
            'attribute1s',
            'attribute2s',
            'attribute3s',
            'attributeLabels',
            'businessUnits'
        ));
    }

    /**
     * Rename a category and update all products and rules
     */
    public function renameCategory(Request $request)
    {
        try {
            $validated = $request->validate([
                'attribute_key' => 'required|in:1,2,3',
                'old_name' => 'required|string',
                'new_name' => 'required|string|max:255'
            ]);
            
            $attributeKey = $validated['attribute_key'];
            $oldName = trim($validated['old_name']);
            $newName = trim($validated['new_name']);
            $column = 'attribute_' . $attributeKey;
            
            if ($oldName === $newName) {
                return response()->json([
                    'success' => false,
                    'message' => 'New name must be different from old name'
                ]);
            }
            
            // Check if new name already exists for other products
            $existingCount = \DB::table('t_crm_prod_product')
                ->where($column, $newName)
                ->count();
            
            if ($existingCount > 0) {
                return response()->json([
                    'success' => false,
                    'message' => "Category '{$newName}' already exists. This will merge {$existingCount} existing products with the renamed category."
                ]);
            }
            
            \DB::beginTransaction();
            
            try {
                // Get product IDs first for logging
                $productIds = \DB::table('t_crm_prod_product')
                    ->where($column, $oldName)
                    ->pluck('id');
                
                // Log category rename for each product
                foreach ($productIds as $productId) {
                    \App\Models\CRM\ProductChangeHistory::logChange([
                        'product_id' => $productId,
                        'change_type' => \App\Models\CRM\ProductChangeHistory::TYPE_CATEGORY_CHANGE,
                        'field_name' => "Category Level {$attributeKey} (Rename)",
                        'old_value' => $oldName,
                        'new_value' => $newName,
                        'change_source' => 'web',
                        'notes' => "Category renamed from '{$oldName}' to '{$newName}'",
                    ]);
                }
                
                // Update all products with old category name to new category name
                $updatedCount = \DB::table('t_crm_prod_product')
                    ->where($column, $oldName)
                    ->update([
                        $column => $newName,
                        'updated_at' => now()
                    ]);
                
                // Update rules in the JSON file
                $allRules = $this->readAttributeAutoRules();
                $rules = $allRules[(string)$attributeKey] ?? [];
                
                foreach ($rules as &$rule) {
                    if (isset($rule['group']) && $rule['group'] === $oldName) {
                        $rule['group'] = $newName;
                    }
                }
                
                $allRules[(string)$attributeKey] = $rules;
                $this->writeAttributeAutoRules($allRules);
                
                \DB::commit();
                
                return response()->json([
                    'success' => true,
                    'message' => "Category renamed successfully. {$updatedCount} products updated.",
                    'products_updated' => $updatedCount
                ]);
                
            } catch (\Exception $e) {
                \DB::rollBack();
                throw $e;
            }
            
        } catch (\Exception $e) {
            \Log::error('Failed to rename category: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to rename category: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check if SKU already exists (AJAX endpoint)
     */
    public function checkSku(Request $request)
    {
        $sku = trim($request->input('sku', ''));
        $variantId = $request->input('variant_id'); // For edit mode, exclude current variant
        
        if (empty($sku)) {
            return response()->json(['exists' => false]);
        }
        
        $query = \App\Models\CRM\ProductVariantModel::where('sku', $sku);
        
        if ($variantId) {
            $query->where('id', '!=', $variantId);
        }
        
        $exists = $query->exists();
        
        return response()->json([
            'exists' => $exists,
            'message' => $exists ? "SKU '{$sku}' is already used by another product" : "SKU is available"
        ]);
    }

    /**
     * Store a manually created product
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'vendor' => 'nullable|string|max:100',
                'product_type' => 'nullable|string|max:100',
                'attribute_1' => 'nullable|string|max:100',
                'attribute_2' => 'nullable|string|max:100',
                'attribute_3' => 'nullable|string|max:100',
                'status' => 'required|in:active,draft,archived',
                'tags' => 'nullable|string',
                'seo_title' => 'nullable|string|max:255',
                'seo_description' => 'nullable|string|max:500',
                'track_inventory' => 'nullable|boolean',
                'is_lean' => 'nullable|boolean',
                'is_active' => 'nullable|boolean',
                'weight_factor' => 'nullable|numeric|min:0.01',
                'unit_weight_kg' => 'nullable|numeric|min:0.001|max:99999',
                'czerlop_product_id' => 'nullable|integer|min:1|max:99999',
                'sell_unit' => 'nullable|in:kg,pcs', // 📦 kg = weighed | pcs = counted boxes
                // 📦 Bundled product (Oct-2026) — rows checked in bundlePlan()
                'is_bundle' => 'nullable|boolean',
                'bundle_items' => 'nullable|array|max:' . \App\Services\CRM\ProductBundle::MAX_ITEMS,
                'bundle_items.*.variant_id' => 'nullable|string|max:40',
                'bundle_items.*.qty' => 'nullable',
                'bundle_items.*.unit_price' => 'nullable',
                'business_unit_id' => 'nullable|exists:t_fin_business_units,id',

                // Variants
                'variants' => 'required|array|min:1',
                'variants.*.title' => 'nullable|string|max:255',
                'variants.*.sku' => 'nullable|string|max:100',
                'variants.*.price' => 'required|numeric|min:0',
                'variants.*.compare_at_price' => 'nullable|numeric|min:0',
                'variants.*.cost_price' => 'nullable|numeric|min:0',
                'variants.*.inventory_quantity' => 'required|integer|min:0',
                'variants.*.weight' => 'nullable|numeric|min:0',
                'variants.*.weight_unit' => 'nullable|string|in:g,kg,oz,lb',
                'variants.*.barcode' => 'nullable|string|max:100',
            ]);

            // Check for duplicate SKUs
            foreach ($validated['variants'] as $index => $variant) {
                if (!empty($variant['sku'])) {
                    $existingSku = \App\Models\CRM\ProductVariantModel::where('sku', $variant['sku'])->first();
                    if ($existingSku) {
                        return back()->withInput()
                            ->withErrors(['variants.' . $index . '.sku' => "SKU '{$variant['sku']}' already exists in the system. Please use a unique SKU."])
                            ->with('error', "Duplicate SKU found: {$variant['sku']}");
                    }
                }
            }

            // 📦 Bundled set-up (new product: absent = not a bundle). May pin the
            // variant price to the bundle total; throws a ValidationException.
            $bundlePlan = $this->bundlePlan($validated, null);

            // 🍱 Recipe family block — checked BEFORE anything is saved.
            $familyAnswer = $this->readRecipeFamily($request, (int) ($request->business_unit_id ?? 1), !empty($validated['is_bundle']));

            // Format data to match API structure
            $productData = $this->formatManualProductData($validated);

            // For single-variant products, default variant title to product title if empty
            if (isset($productData['variants'])) {
                foreach ($productData['variants'] as $index => $variantData) {
                    if (count($productData['variants']) == 1 && empty($productData['variants'][$index]['title'])) {
                        $productData['variants'][$index]['title'] = $productData['title'];
                    }
                }
            }

            // Use the same function as API to maintain consistency.
            // 📦 Product + its bundle rows commit together — a bundle must never exist
            // half-saved (a Shopify order would then bill the bundle as one line).
            $product = \DB::transaction(function () use ($productData, $bundlePlan) {
                $product = ProductModel::storeProductFromApi($productData);
                $this->applyBundlePlan($product, $bundlePlan);
                return $product;
            });

            // Set business unit (defaults to Nizami Farms id=1 if not provided)
            $product->business_unit_id = $request->business_unit_id ?? 1;
            $product->save();

            // 🍱 …and applied through the one engine once the product exists.
            $family = $this->applyRecipeFamily($product, $familyAnswer);
            $createdMsg = 'Product created successfully.' . ($family ? ' ' . $family['message'] : '');

            // Return JSON for API requests
            if ($request->expectsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                return response()->json([
                    'success' => true,
                    'message' => $createdMsg,
                    'product' => $product->fresh(),
                    'recipe_family' => $family,
                ]);
            }

            return redirect()->route('products.index')
                ->with('success', $createdMsg);

        } catch (\Illuminate\Validation\ValidationException $e) {
            if ($request->expectsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $e->errors()
                ], 422);
            }
            return back()->withInput()
                ->withErrors($e->errors());
        } catch (\Exception $e) {
            Log::error('Error creating manual product: ' . $e->getMessage());
            if ($request->expectsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to create product: ' . $e->getMessage()
                ], 500);
            }
            return back()->withInput()
                ->with('error', 'Failed to create product. Please try again.');
        }
    }

    /**
     * Show the form for editing the specified product
     */
    public function edit($id)
    {
        try {
            // Store the previous URL (with filters) in session for "Back to Products" button
            if (url()->previous() !== url()->current()) {
                $previousUrl = url()->previous();
                // Only store if it's from the products index page
                if (strpos($previousUrl, route('products.index')) !== false || strpos($previousUrl, '/products') !== false) {
                    session(['products_return_url' => $previousUrl]);
                }
            }
            
            $product = ProductModel::with('variants')->findOrFail($id);
            
            // Get distinct values for dropdowns (same as create page)
            $productTypes = ProductModel::distinct()->pluck('product_type')->filter()->sort()->values();
            $vendors = ProductModel::distinct()->pluck('vendor')->filter()->sort()->values();
            $attribute1s = ProductModel::distinct()->pluck('attribute_1')->filter()->sort()->values();
            $attribute2s = ProductModel::distinct()->pluck('attribute_2')->filter()->sort()->values();
            $attribute3s = ProductModel::distinct()->pluck('attribute_3')->filter()->sort()->values();
            
            // Get attribute labels
            $attributeLabels = [
                '1' => 'Category Level 1',
                '2' => 'Category Level 2',
                '3' => 'Category Level 3'
            ];
            
            // Get business units for dropdown
            $businessUnits = \App\Models\FIN\BusinessUnitModel::where('is_active', 1)->ordered()->get();
            
            // Check if user can delete products (Taimur role only)
            $canDeleteProduct = $this->userCanDeleteProducts();
            
            return view('pages.products.edit', compact(
                'product',
                'productTypes',
                'vendors',
                'attribute1s',
                'attribute2s',
                'attribute3s',
                'attributeLabels',
                'businessUnits',
                'canDeleteProduct'
            ));
        } catch (\Exception $e) {
            Log::error('Error fetching product for edit: ' . $e->getMessage());
            return redirect()->route('products.index')
                ->with('error', 'Product not found.');
        }
    }

    /**
     * Update the specified product
     */
    public function update(Request $request, $id)
    {
        try {
            $product = ProductModel::findOrFail($id);

            // Allow editing for all products (manual or imported). No restrictions.

            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'vendor' => 'nullable|string|max:100',
                'product_type' => 'nullable|string|max:100',
                'attribute_1' => 'nullable|string|max:100',
                'attribute_2' => 'nullable|string|max:100',
                'attribute_3' => 'nullable|string|max:100',
                'status' => 'required|in:active,draft,archived',
                'tags' => 'nullable|string',
                'seo_title' => 'nullable|string|max:255',
                'seo_description' => 'nullable|string|max:500',
                'track_inventory' => 'nullable|boolean',
                'is_lean' => 'nullable|boolean',
                'is_active' => 'nullable|boolean',
                'weight_factor' => 'nullable|numeric|min:0.01',
                'unit_weight_kg' => 'nullable|numeric|min:0.001|max:99999',
                'czerlop_product_id' => 'nullable|integer|min:1|max:99999',
                'sell_unit' => 'nullable|in:kg,pcs', // 📦 kg = weighed | pcs = counted boxes
                // 📦 Bundled product (Oct-2026) — rows checked in bundlePlan()
                'is_bundle' => 'nullable|boolean',
                'bundle_items' => 'nullable|array|max:' . \App\Services\CRM\ProductBundle::MAX_ITEMS,
                'bundle_items.*.variant_id' => 'nullable|string|max:40',
                'bundle_items.*.qty' => 'nullable',
                'bundle_items.*.unit_price' => 'nullable',
                'business_unit_id' => 'nullable|exists:t_fin_business_units,id',

                // Variants
                'variants' => 'required|array|min:1',
                'variants.*.id' => 'nullable|integer|exists:t_crm_prod_product_variant,id',
                'variants.*.title' => 'nullable|string|max:255',
                'variants.*.sku' => 'nullable|string|max:100',
                'variants.*.price' => 'required|numeric|min:0',
                'variants.*.compare_at_price' => 'nullable|numeric|min:0',
                'variants.*.cost_price' => 'nullable|numeric|min:0',
                'variants.*.inventory_quantity' => 'required|integer|min:0',
                'variants.*.weight' => 'nullable|numeric|min:0',
                'variants.*.weight_unit' => 'nullable|string|in:g,kg,oz,lb',
                'variants.*.barcode' => 'nullable|string|max:100',
            ]);
            
            // Check for duplicate SKUs (excluding current product's variants)
            foreach ($validated['variants'] as $index => $variant) {
                if (!empty($variant['sku'])) {
                    $skuQuery = \App\Models\CRM\ProductVariantModel::where('sku', $variant['sku']);
                    
                    // Exclude current variant if it's being updated
                    if (isset($variant['id'])) {
                        $skuQuery->where('id', '!=', $variant['id']);
                    }
                    
                    $existingSku = $skuQuery->first();
                    if ($existingSku) {
                        return back()->withInput()
                            ->withErrors(['variants.' . $index . '.sku' => "SKU '{$variant['sku']}' already exists in another product. Please use a unique SKU."])
                            ->with('error', "Duplicate SKU found: {$variant['sku']}");
                    }
                }
            }

            // ⭐ Preserve fields the client did not send. The mobile app's product-edit
            //    payload omits unit_weight_kg (older APKs always will), and the formatter
            //    below defaults absent fields — without this line, ANY mobile product save
            //    would silently reset a 0.5 (500g pack) back to 1.000.
            // Key-ABSENT only: a web form that clears the field still sends the key
            // (empty string → null), and that must keep meaning "clear/reset".
            if (!array_key_exists('unit_weight_kg', $validated)) {
                $validated['unit_weight_kg'] = $product->unit_weight_kg ?? 1.000;
            }
            // Same trap, discovered in the same review: mobile never sends czerlop_product_id,
            // and the formatter maps an absent value to NULL — so every mobile product save
            // was wiping the scale PLU (breaking barcode-qty for that product).
            if (!array_key_exists('czerlop_product_id', $validated)) {
                $validated['czerlop_product_id'] = $product->czerlop_product_id;
            }
            // 📦 Same trap again: the mobile product-edit payload does not send
            // sell_unit, and the formatter defaults an absent value to 'kg' — which
            // would turn a frozen BOX product back into a weighed one on any mobile
            // save, silently breaking its box scanning.
            if (!array_key_exists('sell_unit', $validated) && ProductModel::supportsSellUnit()) {
                $validated['sell_unit'] = $product->sell_unit;
            }
            // 📦 Same trap, for the bundle set-up: the phone's product form does not
            // send it, so key-absent keeps the product's bundle exactly as it is (and
            // keeps its price pinned to the bundle total).
            $bundlePlan = $this->bundlePlan($validated, $product);

            // 🍱 Recipe family block — checked BEFORE anything is saved; absent keys = keep.
            $familyAnswer = $this->readRecipeFamily(
                $request,
                (int) ($request->business_unit_id ?? $product->business_unit_id ?? 1),
                $request->has('is_bundle')
                    ? (bool) $request->boolean('is_bundle')
                    : (\App\Services\CRM\ProductBundle::supported() && \App\Services\CRM\ProductBundle::isBundleProductId((int) $product->id))
            );

            // Format data to match API structure
            $productData = $this->formatManualProductData($validated);

            // For updates, we need to include the existing product ID
            $productData['existing_product_id'] = $product->id;
            
            // For updates, we need to include the existing product ID in variants
            if (isset($productData['variants'])) {
                foreach ($productData['variants'] as $index => $variantData) {
                    // If this is an existing variant, preserve its ID
                    if (isset($validated['variants'][$index]['id'])) {
                        $productData['variants'][$index]['id'] = $validated['variants'][$index]['id'];
                    }
                    
                    // For single-variant products, default variant title to product title if empty
                    if (count($productData['variants']) == 1 && empty($productData['variants'][$index]['title'])) {
                        $productData['variants'][$index]['title'] = $productData['title'];
                    }
                }
            }
            
            // Use the same function as API to maintain consistency.
            // 📦 Product + its bundle rows commit together (see store()).
            $updatedProduct = \DB::transaction(function () use ($productData, $bundlePlan) {
                $updatedProduct = ProductModel::storeProductFromApi($productData);
                $this->applyBundlePlan($updatedProduct, $bundlePlan);
                return $updatedProduct;
            });
            
            // Update business unit if provided
            if ($request->has('business_unit_id')) {
                $updatedProduct->business_unit_id = $request->business_unit_id ?? $updatedProduct->business_unit_id ?? 1;
                $updatedProduct->save();
            }

            // 🍱 …and applied through the one engine.
            $family = $this->applyRecipeFamily($updatedProduct, $familyAnswer);
            $updatedMsg = 'Product updated successfully.' . ($family ? ' ' . $family['message'] : '');

            // Return JSON for API requests
            if ($request->expectsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                return response()->json([
                    'success' => true,
                    'message' => $updatedMsg,
                    'product' => $updatedProduct->fresh(),
                    'recipe_family' => $family,
                ]);
            }

            // Stay on the edit page after saving, showing success message
            return redirect()->route('products.edit', $id)
                ->with('success', $updatedMsg);

        } catch (\Illuminate\Validation\ValidationException $e) {
            if ($request->expectsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $e->errors()
                ], 422);
            }
            return back()->withInput()
                ->withErrors($e->errors());
        } catch (\Exception $e) {
            Log::error('Error updating manual product: ' . $e->getMessage());
            if ($request->expectsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to update product: ' . $e->getMessage()
                ], 500);
            }
            return back()->withInput()
                ->with('error', 'Failed to update product. Please try again.');
        }
    }

    /**
     * 🍱 Oct-19: the product forms' "Recipe family" block (web create/edit, phone create/edit).
     * Read and CHECKED before the product is saved — a bad answer is a 422, never a half-saved
     * product. Absent keys = keep: older forms and APKs never send them.
     */
    private function readRecipeFamily(Request $request, int $businessUnitId, bool $isBundle): ?array
    {
        try {
            return (new \App\Services\Khaas\RecipeFamilyService())->readAssignment($request->all(), $businessUnitId, $isBundle);
        } catch (\InvalidArgumentException $e) {
            throw \Illuminate\Validation\ValidationException::withMessages(['recipe_family_id' => $e->getMessage()]);
        }
    }

    /** Apply the checked answer through RecipeFamilyService — the ONE engine. Never undoes the product save. */
    private function applyRecipeFamily(ProductModel $product, ?array $answer): ?array
    {
        if (!$answer) {
            return null;
        }
        // "no family" on a product that never had one, with nothing else to change: nothing to say
        if ($answer['leave'] && !$product->recipe_family_id
            && ($answer['pieces'] === null || (int) $answer['pieces'] === (int) $product->pieces_per_pack)) {
            return null;
        }
        try {
            return (new \App\Services\Khaas\RecipeFamilyService())->assign(
                (int) $product->id, $answer['family_id'], $answer['pieces'], auth()->id(), $answer['new_name']
            );
        } catch (\Throwable $e) {
            Log::warning('Product saved but its recipe family was not set', ['product_id' => $product->id, 'error' => $e->getMessage()]);
            return ['failed' => true, 'message' => 'Its recipe family was NOT set: ' . $e->getMessage()];
        }
    }

    /**
     * Format manual product data to match API structure
     */
    private function formatManualProductData(array $validated): array
    {
        // Calculate price range from variants
        $prices = array_column($validated['variants'], 'price');
        $priceMin = min($prices);
        $priceMax = max($prices);
        
        // Calculate total inventory
        $totalInventory = array_sum(array_column($validated['variants'], 'inventory_quantity'));
        
        // Format variants to match API structure
        $variants = [];
        foreach ($validated['variants'] as $index => $variantData) {
            $variants[] = [
                'id' => $variantData['id'] ?? null, // For updates
                'title' => $variantData['title'],
                'sku' => $variantData['sku'] ?? null,
                'barcode' => $variantData['barcode'] ?? null,
                'price' => $variantData['price'],
                'compare_at_price' => $variantData['compare_at_price'] ?? null,
                'cost_price' => $variantData['cost_price'] ?? null,
                'inventory_quantity' => $variantData['inventory_quantity'],
                'inventory_policy' => 'deny', // Default for manual products
                'weight' => $variantData['weight'] ?? null,
                'weight_unit' => $variantData['weight_unit'] ?? 'g',
                'position' => $index + 1,
                'available' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Parse tags
        $tags = [];
        if (!empty($validated['tags'])) {
            $tags = array_map('trim', explode(',', $validated['tags']));
        }

        // Format data to match API structure
        $productData = [
            // No Shopify IDs for manual products
            'shopify_product_id' => null,
            'shopify_handle' => null,
            
            // Basic info
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'vendor' => $validated['vendor'] ?? null,
            'product_type' => $validated['product_type'] ?? null,
            'attribute_1' => $validated['attribute_1'] ?? null,
            'attribute_2' => $validated['attribute_2'] ?? null,
            'attribute_3' => $validated['attribute_3'] ?? null,
            'status' => $validated['status'],
            'published_at' => $validated['status'] === 'active' ? now() : null,
            
            // Pricing
            'price_min' => $priceMin,
            'price_max' => $priceMax,
            
            // Inventory
            'total_inventory' => $totalInventory,
            'track_inventory' => filter_var($validated['track_inventory'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'is_lean' => filter_var($validated['is_lean'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'weight_factor' => $validated['weight_factor'] ?? 1.00,
            // ⭐ Kg per ONE qty unit (0.5 = a 500g pack). Display-only: it feeds the Weight
            //    column on Open Order Quantities and never changes a stored quantity.
            'unit_weight_kg' => $validated['unit_weight_kg'] ?? 1.000,
            // ⭐ Czerlop scale PLU (barcode-qty feature). Null = not on the scale / untagged.
            'czerlop_product_id' => isset($validated['czerlop_product_id']) && $validated['czerlop_product_id'] !== ''
                ? (int) $validated['czerlop_product_id']
                : null,

            // SEO
            'seo_title' => $validated['seo_title'] ?? null,
            'seo_description' => $validated['seo_description'] ?? null,
            
            // Media (manual products start without images)
            'featured_image' => null,
            'images' => [],
            
            // Organization
            'tags' => $tags,
            'options' => [], // Manual products can have simple options later
            
            // Sync status (manual products are not synced)
            'sync_status' => 'manual',
            'last_synced_at' => null,
            'shopify_created_at' => null,
            'shopify_updated_at' => null,
            
            // Activity
            'is_active' => filter_var($validated['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN),
            
            // Variants
            'variants' => $variants
        ];

        // 📦 Sold by weight or by the box. Added only when the column exists, so a
        // web upload that lands before the SQL cannot break every product save with
        // an unknown-column error. Anything unrecognised (and an absent value)
        // normalises to 'kg' — a product can never drift into 'pcs' by accident.
        if (ProductModel::supportsSellUnit()) {
            $productData['sell_unit'] = ProductModel::normaliseSellUnit($validated['sell_unit'] ?? null);
        }

        return $productData;
    }

    /**
     * 📦 Settle the bundle set-up for a product save (create: $product = null).
     *
     *   is_bundle ABSENT → keep the product's bundle rows as they are (the phone's
     *                      product form and older clients never send them; a new
     *                      product is not a bundle)
     *   is_bundle = 0    → not a bundle any more (rows removed)
     *   is_bundle = 1    → these rows, after ProductBundle::setupError()
     *
     * Whenever the product IS (or stays) a bundle, its variant price is pinned to the
     * bundle total — the Shopify price — so any path that does not split the bundle
     * still bills the right money. Removes the bundle keys from $validated.
     *
     * @return array{action:string, rows:array}  action: keep | clear | set
     * @throws \Illuminate\Validation\ValidationException (the save's own catch turns it
     *         into the form error / 422 JSON)
     */
    private function bundlePlan(array &$validated, ?ProductModel $product): array
    {
        $flag = $validated['is_bundle'] ?? null;
        $posted = $validated['bundle_items'] ?? [];
        unset($validated['is_bundle'], $validated['bundle_items']);

        if (!\App\Services\CRM\ProductBundle::supported()) {
            return ['action' => 'keep', 'rows' => []];
        }

        if ($flag === null) {
            $rows = $product ? \App\Services\CRM\ProductBundle::itemRows((int) $product->id) : [];
            if ($rows) {
                $this->pinBundlePrice($validated, \App\Services\CRM\ProductBundle::totalOf($rows));
            }
            return ['action' => 'keep', 'rows' => $rows];
        }
        if (!filter_var($flag, FILTER_VALIDATE_BOOLEAN)) {
            return ['action' => 'clear', 'rows' => []];
        }

        $rows = \App\Services\CRM\ProductBundle::normaliseRows($posted);
        $error = \App\Services\CRM\ProductBundle::setupError(
            $product ? (int) $product->id : null,
            $rows,
            count($validated['variants'] ?? [])
        );
        if ($error) {
            throw \Illuminate\Validation\ValidationException::withMessages(['bundle_items' => $error]);
        }
        $this->pinBundlePrice($validated, \App\Services\CRM\ProductBundle::totalOf($rows));
        return ['action' => 'set', 'rows' => $rows];
    }

    /** 📦 A bundle's own (only) variant price = the bundle total. */
    private function pinBundlePrice(array &$validated, float $total): void
    {
        foreach (array_keys($validated['variants'] ?? []) as $i) {
            $validated['variants'][$i]['price'] = $total;
        }
    }

    /** 📦 Write the plan's rows for a just-saved product + one history line if it changed. */
    private function applyBundlePlan(ProductModel $product, array $plan): void
    {
        if ($plan['action'] === 'keep' || !\App\Services\CRM\ProductBundle::supported()) {
            return;
        }
        $before = \App\Services\CRM\ProductBundle::summary((int) $product->id);
        \App\Services\CRM\ProductBundle::saveRows((int) $product->id, $plan['rows'], auth()->id());
        $after = \App\Services\CRM\ProductBundle::summary((int) $product->id);
        if ($before !== $after) {
            \App\Models\CRM\ProductChangeHistory::logChange([
                'product_id' => $product->id,
                'change_type' => \App\Models\CRM\ProductChangeHistory::TYPE_BUNDLE_CHANGE,
                'field_name' => 'Bundle',
                'old_value' => $before !== '' ? $before : null,
                'new_value' => $after !== '' ? $after : null,
                'change_source' => \App\Models\CRM\ProductChangeHistory::detectSource(),
            ]);
        }
    }

    /**
     * Get product change history
     */
    public function getHistory($id)
    {
        try {
            $product = ProductModel::findOrFail($id);
            
            $limit = request()->input('limit', 3);
            $limit = min((int)$limit, 50); // Max 50 records
            
            $history = \App\Models\CRM\ProductChangeHistory::getRecentChanges($product->id, $limit);
            
            return response()->json([
                'success' => true,
                'product_id' => $product->id,
                'product_title' => $product->title,
                'history' => $history,
                'total_changes' => $product->changeHistory()->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching product history: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch product history: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a product and its variants (Taimur role only)
     * Only allowed if the product has not been used in any orders
     */
    public function destroy($id)
    {
        try {
            $user = auth()->user();
            if (!$user) {
                $message = 'Unauthorized.';
                return request()->expectsJson()
                    ? response()->json(['success' => false, 'message' => $message], 401)
                    : redirect()->back()->with('error', $message);
            }

            // Check if user has Taimur role (by role name, not ID)
            $hasTaimurRole = $user->roles()
                ->whereRaw('LOWER(urole_name) = ?', ['taimur'])
                ->exists();

            if (!$hasTaimurRole) {
                $message = 'Only Taimur role can delete products.';
                return request()->expectsJson()
                    ? response()->json(['success' => false, 'message' => $message], 403)
                    : redirect()->back()->with('error', $message);
            }

            $product = ProductModel::with('variants')->findOrFail($id);

            // Check if product is referenced in any order line items
            $orderLineItemCount = \DB::table('t_crm_prod_order_line_item')
                ->where('product_id', $product->id)
                ->count();

            if ($orderLineItemCount > 0) {
                $message = "Cannot delete \"{$product->title}\" — it has been used in {$orderLineItemCount} order line item(s). You can set its status to \"archived\" instead.";
                return request()->expectsJson()
                    ? response()->json(['success' => false, 'message' => $message], 422)
                    : redirect()->back()->with('error', $message);
            }

            // 📦 A product INSIDE a bundle: deleting it would leave every Shopify order
            // for that bundle unconvertible (App\Services\CRM\ProductBundle).
            if ($product->variants->isNotEmpty()) {
                $bundleTitle = \App\Services\CRM\ProductBundle::bundleHolding($product->variants->pluck('id'), (int) $product->id);
                if ($bundleTitle) {
                    $message = "Cannot delete \"{$product->title}\" — it is inside the bundle \"{$bundleTitle}\". Change or remove that bundle first.";
                    return request()->expectsJson()
                        ? response()->json(['success' => false, 'message' => $message], 422)
                        : redirect()->back()->with('error', $message);
                }
            }

            // Check if product is referenced in warehouse inventory
            $warehouseCount = \DB::table('t_crm_warehouse_inventory')
                ->where('product_id', $product->id)
                ->count();

            if ($warehouseCount > 0) {
                $message = "Cannot delete \"{$product->title}\" — it has {$warehouseCount} warehouse inventory record(s). Please clear inventory first.";
                return request()->expectsJson()
                    ? response()->json(['success' => false, 'message' => $message], 422)
                    : redirect()->back()->with('error', $message);
            }

            // ⚠️ Packets physically sitting in the chiller/freezer right now.
            //    Unlike the two checks above this is NOT a hard block — the product can
            //    still be deleted, but its packets must come out with it and the user has
            //    to say so explicitly. Without this the FK (ON DELETE SET NULL) would
            //    silently orphan them: they stay "stored" forever, still counted in the
            //    room's total, with no product to categorise them under.
            //    ⭐ Only status='stored' matters — historical taken_out rows never block.
            $storedPackets = \DB::table('t_crm_overnight_item')
                ->where('product_id', $product->id)
                ->where('status', 'stored')
                ->get(['id', 'section', 'quantity', 'unit']);

            if ($storedPackets->isNotEmpty() && !request()->boolean('remove_overnight')) {
                $summary = $this->describeOvernightPackets($storedPackets);
                $message = "\"{$product->title}\" has {$summary} in overnight storage right now. "
                    . 'Deleting it will also take those packets out of the chiller/freezer.';

                return request()->expectsJson()
                    ? response()->json([
                        'success' => false,
                        'requires_confirmation' => 'overnight',
                        'message' => $message,
                        'confirm_message' => $message . ' Continue?',
                        'overnight' => [
                            'packets' => $storedPackets->count(),
                            'summary' => $summary,
                        ],
                    ], 422)
                    : redirect()->back()->with('error', $message);
            }

            $productTitle = $product->title;
            $variantCount = $product->variants->count();

            // Delete in correct order: overnight take-out → change history → variants → product
            \DB::beginTransaction();
            try {
                // ⭐ Take the packets out through the NORMAL path, inside this same
                //    transaction: flip status + stamp who/when, and write one 'out' log
                //    row per packet. The item rows themselves are never hard-deleted —
                //    the overnight log is append-only and the daily summary walks it, so
                //    a silent removal would leave the section balance short forever.
                if ($storedPackets->isNotEmpty()) {
                    $now = now();
                    foreach ($storedPackets as $packet) {
                        \DB::table('t_crm_overnight_item')
                            ->where('id', $packet->id)
                            ->update([
                                'status' => 'taken_out',
                                'taken_out_at' => $now,
                                'taken_out_by' => $user->id,
                                'updated_at' => $now,
                            ]);

                        \DB::table('t_crm_overnight_log')->insert([
                            'item_id' => $packet->id,
                            'action' => 'out',
                            'from_section' => $packet->section,
                            'to_section' => null,
                            'product_id' => $product->id,
                            'product_name' => $product->title,
                            'quantity' => $packet->quantity,
                            'unit' => $packet->unit,
                            'source' => 'manual',
                            'created_by' => $user->id,
                            'created_at' => $now,
                        ]);
                    }

                    Log::warning('Overnight packets taken out because their product was deleted', [
                        'product_id' => $product->id,
                        'product_title' => $product->title,
                        'packets' => $storedPackets->count(),
                        'by' => $user->id,
                    ]);
                }

                // Delete change history
                \DB::table('t_crm_prod_product_change_history')
                    ->where('product_id', $product->id)
                    ->delete();

                // Delete variants
                \DB::table('t_crm_prod_product_variant')
                    ->where('product_id', $product->id)
                    ->delete();

                // 📦 If this product IS a bundle, its rows go with it
                if (\App\Services\CRM\ProductBundle::supported()) {
                    \App\Services\CRM\ProductBundle::saveRows((int) $product->id, [], null);
                }

                // Delete the product itself
                $product->delete();

                \DB::commit();

                Log::info("Product deleted", [
                    'product_id' => $id,
                    'product_title' => $productTitle,
                    'variants_deleted' => $variantCount,
                    'deleted_by' => $user->id,
                    'deleted_by_name' => $user->fullname,
                ]);

                $message = "Product \"{$productTitle}\" and its {$variantCount} variant(s) have been deleted successfully.";
                return request()->expectsJson()
                    ? response()->json(['success' => true, 'message' => $message])
                    : redirect()->route('products.index')->with('success', $message);

            } catch (\Exception $e) {
                \DB::rollBack();
                throw $e;
            }

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            $message = 'Product not found.';
            return request()->expectsJson()
                ? response()->json(['success' => false, 'message' => $message], 404)
                : redirect()->route('products.index')->with('error', $message);
        } catch (\Exception $e) {
            Log::error('Error deleting product: ' . $e->getMessage(), [
                'product_id' => $id,
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString(),
            ]);
            $message = 'Failed to delete product: ' . $e->getMessage();
            return request()->expectsJson()
                ? response()->json(['success' => false, 'message' => $message], 500)
                : redirect()->back()->with('error', $message);
        }
    }

    /**
     * "6 packets (50.62 kg) in the freezer" — built server-side so the web page and
     * the app warn with identical wording instead of each formatting its own.
     *
     * ⚠️ kg and pcs are never added together (the overnight unit is a kg|pcs enum).
     */
    private function describeOvernightPackets($packets): string
    {
        $count = $packets->count();
        $sections = [];
        $kg = 0.0;
        $pcs = 0.0;

        foreach ($packets as $packet) {
            $sections[strtolower((string) $packet->section)] = true;
            if (strtolower((string) $packet->unit) === 'pcs') {
                $pcs += (float) $packet->quantity;
            } else {
                $kg += (float) $packet->quantity;
            }
        }

        $amounts = [];
        if ($kg > 0) {
            $amounts[] = round($kg, 2) . ' kg';
        }
        if ($pcs > 0) {
            $amounts[] = round($pcs, 2) . ' pcs';
        }

        $where = implode(' and the ', array_keys($sections));

        return $count . ' packet' . ($count === 1 ? '' : 's')
            . (empty($amounts) ? '' : ' (' . implode(' + ', $amounts) . ')')
            . ($where === '' ? '' : ' in the ' . $where);
    }

    /**
     * Check if the current user has the Taimur role (helper for views)
     */
    private function userCanDeleteProducts(): bool
    {
        $user = auth()->user();
        if (!$user) return false;
        
        return $user->roles()
            ->whereRaw('LOWER(urole_name) = ?', ['taimur'])
            ->exists();
    }
}
