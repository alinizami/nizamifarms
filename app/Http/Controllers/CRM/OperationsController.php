<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\CRM\OrderModel;
use App\Models\CRM\CustomerModel;

class OperationsController extends Controller
{
    /**
     * ⭐ App-release broadcast (Operations page button). Sends an FCM push to
     * every active mobile device announcing the CURRENT version served by
     * /api/app/version (single source of truth — the build script bumps it).
     * Owner presses this manually AFTER uploading the new APK + AppController
     * so the announced download link is always live. Tapping the notification
     * opens the app, which shows its standard update dialog.
     */
    public function notifyAppUpdate(Request $request)
    {
        // One HTTP call to FCM per device (~0.2-0.5s each); allow headroom
        // beyond shared-hosting default max_execution_time.
        set_time_limit(300);

        // Reuse the exact version payload the mobile app itself sees.
        $version = app(\App\Http\Controllers\API\AppController::class)
            ->getLatestVersion()
            ->getData(true)['version'];

        $result = app(\App\Services\FirebaseService::class)->notifyAppUpdate($version);

        Log::info('Operations: app update notification triggered', [
            'by_user' => auth()->id(),
            'version' => $version['name'],
            'result'  => $result,
        ]);

        return response()->json([
            'success' => $result['sent'] > 0,
            'version' => $version['name'],
            'total'   => $result['total'],
            'sent'    => $result['sent'],
            'failed'  => $result['failed'],
            'error'   => $result['error'],
        ]);
    }

    /**
     * Import historical orders from CSV file
     * File location: public/downloads/NF_Data_Center_Data_History.csv
     */
    public function importHistoryOrders(Request $request)
    {
        set_time_limit(0); // Allow unlimited execution time
        ini_set('memory_limit', '1G'); // Increase memory for large CSV
        
        $csvPath = public_path('downloads/NF_Data_Center_Data_History.csv');
        
        if (!file_exists($csvPath)) {
            return response()->json([
                'success' => false,
                'message' => 'CSV file not found at: public/downloads/NF_Data_Center_Data_History.csv'
            ], 404);
        }
        
        // Check if tables exist
        if (!DB::getSchemaBuilder()->hasTable('t_crm_history_order')) {
            return response()->json([
                'success' => false,
                'message' => 'History tables not created. Please run the migration SQL first: database/migrations/create_history_orders_tables_dec04_2025.sql'
            ], 400);
        }
        
        $batchId = 'CSV_' . date('Ymd_His');
        $stats = [
            'orders_created' => 0,
            'orders_skipped_duplicate' => 0,
            'line_items_created' => 0,
            'customers_created' => 0,
            'customers_updated' => 0,
            'skipped_rows' => 0,
            'errors' => []
        ];
        
        // Pre-load existing order numbers for faster duplicate detection
        $existingOrderNumbers = DB::table('t_crm_history_order')
            ->pluck('order_number')
            ->flip()
            ->toArray();
        
        try {
            // Open file with proper handling for multiline fields
            $handle = fopen($csvPath, 'r');
            if (!$handle) {
                throw new \Exception('Cannot open CSV file');
            }
            
            // Read header
            $header = fgetcsv($handle);
            if (!$header) {
                fclose($handle);
                throw new \Exception('Cannot read CSV header');
            }
            
            // Normalize header names
            $headerMap = [];
            foreach ($header as $idx => $col) {
                $headerMap[strtolower(trim($col))] = $idx;
            }
            
            // Group line items by Order Number
            $orders = [];
            $rowNum = 1;
            
            while (($row = fgetcsv($handle)) !== false) {
                $rowNum++;
                
                try {
                    $orderNumber = trim($row[$headerMap['order number'] ?? -1] ?? '');
                    if (empty($orderNumber)) {
                        $stats['skipped_rows']++;
                        continue;
                    }
                    
                    // Get order-level data (first occurrence wins)
                    if (!isset($orders[$orderNumber])) {
                        $orders[$orderNumber] = [
                            'order_number' => $orderNumber,
                            'order_status' => $this->normalizeHistoryStatus($row[$headerMap['order status'] ?? -1] ?? ''),
                            'order_date' => $this->parseHistoryDate($row[$headerMap['order date'] ?? -1] ?? '', 'dmy'),
                            'delivered_at' => $this->parseHistoryDate($row[$headerMap['delivery date'] ?? -1] ?? '', 'mdy'),
                            'first_name' => trim($row[$headerMap['first name (billing)'] ?? -1] ?? ''),
                            'last_name' => trim($row[$headerMap['last name (billing)'] ?? -1] ?? ''),
                            'address_line1' => trim($row[$headerMap['address 1 (billing)'] ?? -1] ?? ''),
                            'city' => trim($row[$headerMap['city (billing)'] ?? -1] ?? ''),
                            'email' => trim($row[$headerMap['email (billing)'] ?? -1] ?? ''),
                            'phone' => $this->cleanPhone($row[$headerMap['mobile number'] ?? -1] ?? ''),
                            'payment_method' => $this->normalizePaymentMethod($row[$headerMap['payment method'] ?? -1] ?? ''),
                            'discount_total' => $this->cleanAmount($row[$headerMap['cart discount amount'] ?? -1] ?? '0'),
                            'shipping_total' => $this->cleanAmount($row[$headerMap['order shipping amount'] ?? -1] ?? '0'),
                            'total_price' => $this->cleanAmount($row[$headerMap['order total amount'] ?? -1] ?? '0'),
                            'coupon_code' => trim($row[$headerMap['coupon code'] ?? -1] ?? ''),
                            'note' => trim($row[$headerMap['notes'] ?? -1] ?? ''),
                            'raw_products_text' => trim($row[$headerMap['products'] ?? -1] ?? ''),
                            'line_items' => []
                        ];
                    }
                    
                    // Add line item
                    $orders[$orderNumber]['line_items'][] = [
                        'external_line_item_id' => trim($row[$headerMap['item #'] ?? -1] ?? ''),
                        'sku' => trim($row[$headerMap['sku'] ?? -1] ?? ''),
                        'name' => trim($row[$headerMap['item name'] ?? -1] ?? ''),
                        'quantity' => $this->cleanAmount($row[$headerMap['quantity'] ?? -1] ?? '1'),
                        'unit_price' => $this->cleanAmount($row[$headerMap['item cost before discount'] ?? -1] ?? '0'),
                        'line_subtotal' => $this->cleanAmount($row[$headerMap['order line subtotal'] ?? -1] ?? '0'),
                        'discount_amount' => $this->cleanAmount($row[$headerMap['discount amount'] ?? -1] ?? '0'),
                    ];
                    
                } catch (\Throwable $e) {
                    $stats['errors'][] = "Row {$rowNum}: " . $e->getMessage();
                    if (count($stats['errors']) > 100) {
                        $stats['errors'][] = '... (errors truncated)';
                        break;
                    }
                }
            }
            
            fclose($handle);
            
            Log::info('History import: CSV parsed', ['total_orders' => count($orders)]);
            
            // Now process each order in a transaction
            $processedCount = 0;
            foreach ($orders as $orderNumber => $orderData) {
                try {
                    // =========================================
                    // DUPLICATE CHECK - Skip if already exists
                    // =========================================
                    if (isset($existingOrderNumbers[$orderNumber])) {
                        $stats['orders_skipped_duplicate']++;
                        $processedCount++;
                        continue; // Skip to next order
                    }
                    
                    DB::beginTransaction();
                    
                    // Find or create customer if phone exists
                    $customerId = null;
                    if (!empty($orderData['phone'])) {
                        $customerResult = $this->findOrCreateHistoryCustomer(
                            $orderData['phone'],
                            $orderData,
                            $stats
                        );
                        $customerId = $customerResult['customer_id'];
                    }
                    
                    // Calculate subtotal from line items
                    $subtotal = 0;
                    foreach ($orderData['line_items'] as $li) {
                        $subtotal += floatval($li['line_subtotal']);
                    }
                    
                    // Insert order
                    $orderId = DB::table('t_crm_history_order')->insertGetId([
                        'customer_id' => $customerId,
                        'external_source' => 'csv_import',
                        'external_id' => $orderNumber,
                        'order_number' => $orderNumber,
                        'order_status' => $orderData['order_status'],
                        'order_date' => $orderData['order_date'],
                        'delivered_at' => $orderData['delivered_at'],
                        'name' => trim($orderData['first_name'] . ' ' . $orderData['last_name']),
                        'currency' => 'PKR',
                        'contact_email' => $orderData['email'] ?: null,
                        'subtotal_price' => $subtotal,
                        'discount_total' => $orderData['discount_total'],
                        'shipping_total' => $orderData['shipping_total'],
                        'total_price' => $orderData['total_price'],
                        'address_first_name' => $orderData['first_name'] ?: null,
                        'address_last_name' => $orderData['last_name'] ?: null,
                        'address_email' => $orderData['email'] ?: null,
                        'address_phone' => $orderData['phone'] ?: null,
                        'address_line1' => $orderData['address_line1'] ?: null,
                        'address_city' => $orderData['city'] ?: null,
                        'address_country' => 'Pakistan',
                        'coupon_code' => $orderData['coupon_code'] ?: null,
                        'payment_method' => $orderData['payment_method'],
                        'note' => $orderData['note'] ?: null,
                        'raw_products_text' => $orderData['raw_products_text'] ?: null,
                        'import_batch_id' => $batchId,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                    
                    $stats['orders_created']++;
                    
                    // Insert line items
                    foreach ($orderData['line_items'] as $li) {
                        DB::table('t_crm_history_order_line_item')->insert([
                            'order_id' => $orderId,
                            'external_line_item_id' => $li['external_line_item_id'] ?: null,
                            'sku' => $li['sku'] ?: null,
                            'name' => $li['name'] ?: null,
                            'quantity' => $li['quantity'],
                            'unit_price' => $li['unit_price'],
                            'line_subtotal' => $li['line_subtotal'],
                            'discount_amount' => $li['discount_amount'],
                            'line_total' => $li['line_subtotal'], // Same as subtotal for history
                            'created_at' => now(),
                            'updated_at' => now()
                        ]);
                        $stats['line_items_created']++;
                    }
                    
                    DB::commit();
                    $processedCount++;
                    
                    // Mark this order as existing (for any subsequent duplicates in same batch)
                    $existingOrderNumbers[$orderNumber] = true;
                    
                    // Log progress every 500 orders
                    if ($processedCount % 500 === 0) {
                        Log::info('History import progress', [
                            'processed' => $processedCount, 
                            'total' => count($orders),
                            'created' => $stats['orders_created'],
                            'skipped_dup' => $stats['orders_skipped_duplicate']
                        ]);
                    }
                    
                } catch (\Throwable $e) {
                    DB::rollBack();
                    $stats['errors'][] = "Order {$orderNumber}: " . $e->getMessage();
                    if (count($stats['errors']) > 100) {
                        break;
                    }
                }
            }
            
            Log::info('History import completed', $stats);
            
            // Get summary of what's in the database now
            $summary = [
                'total_history_orders' => DB::table('t_crm_history_order')->count(),
                'total_history_line_items' => DB::table('t_crm_history_order_line_item')->count(),
                'total_history_revenue' => DB::table('t_crm_history_order')
                    ->where('order_status', 'delivered')
                    ->sum('total_price'),
                'date_range' => [
                    'earliest' => DB::table('t_crm_history_order')->min('order_date'),
                    'latest' => DB::table('t_crm_history_order')->max('order_date')
                ]
            ];
            
            $message = "Import completed successfully!";
            if ($stats['orders_skipped_duplicate'] > 0) {
                $message .= " ({$stats['orders_skipped_duplicate']} duplicates skipped)";
            }
            
            return response()->json([
                'success' => true,
                'message' => $message,
                'batch_id' => $batchId,
                'stats' => $stats,
                'database_summary' => $summary
            ]);
            
        } catch (\Throwable $e) {
            Log::error('History import failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            
            return response()->json([
                'success' => false,
                'message' => 'Import failed: ' . $e->getMessage(),
                'stats' => $stats
            ], 500);
        }
    }
    
    /**
     * Find or create customer for history import
     * Uses same phone normalization as production
     */
    private function findOrCreateHistoryCustomer(string $phone, array $orderData, array &$stats): array
    {
        // Normalize phone (take last 10 digits)
        $phoneData = CustomerModel::normalizePhone($phone);
        $normalizedPhone = $phoneData['normalized'];
        
        // Find existing customer (prefer non-merged, follow merge chain if needed)
        $customer = DB::table('t_crm_prod_customer')
            ->where('phone_normalized', $normalizedPhone)
            ->whereNull('merged_into_customer_id')
            ->first();
        
        if (!$customer) {
            // Check if there's a merged customer - follow chain to primary
            $mergedCustomer = DB::table('t_crm_prod_customer')
                ->where('phone_normalized', $normalizedPhone)
                ->whereNotNull('merged_into_customer_id')
                ->first();
            if ($mergedCustomer) {
                $primaryId = $mergedCustomer->merged_into_customer_id;
                $primary = DB::table('t_crm_prod_customer')
                    ->where('id', $primaryId)
                    ->whereNull('merged_into_customer_id')
                    ->first();
                if ($primary) {
                    $customer = $primary;
                }
            }
        }
        
        $orderDate = $orderData['order_date'];
        $isDelivered = in_array($orderData['order_status'], ['delivered', 'completed']);
        
        if (!$customer) {
            // Create new customer
            $customerId = DB::table('t_crm_prod_customer')->insertGetId([
                'phone' => $normalizedPhone,
                'phone_normalized' => $normalizedPhone,
                'phone_original' => $phone,
                'first_name' => $orderData['first_name'] ?: null,
                'last_name' => $orderData['last_name'] ?: null,
                'email' => $orderData['email'] ?: null,
                'address1' => $orderData['address_line1'] ?: null,
                'city' => $orderData['city'] ?: null,
                'country' => 'Pakistan',
                // Only set dates if order is delivered/completed
                'first_order_date' => $isDelivered ? $orderDate : null,
                'last_order_date' => $isDelivered ? $orderDate : null,
                'total_orders' => 0, // Will be calculated from history view
                'total_spent' => 0,  // Will be calculated from history view
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now()
            ]);
            $stats['customers_created']++;
            
            return ['customer_id' => $customerId, 'is_new' => true];
        } else {
            // Update existing customer's first_order_date if this is earlier
            $updates = [];
            
            if ($isDelivered && $orderDate) {
                // Update first_order_date if this order is earlier
                if (!$customer->first_order_date || $orderDate < $customer->first_order_date) {
                    $updates['first_order_date'] = $orderDate;
                }
                // Update last_order_date if this order is later
                if (!$customer->last_order_date || $orderDate > $customer->last_order_date) {
                    $updates['last_order_date'] = $orderDate;
                }
            }
            
            if (!empty($updates)) {
                $updates['updated_at'] = now();
                DB::table('t_crm_prod_customer')
                    ->where('id', $customer->id)
                    ->update($updates);
                $stats['customers_updated']++;
            }
            
            return ['customer_id' => $customer->id, 'is_new' => false];
        }
    }
    
    /**
     * Parse date from CSV in various formats
     * @param string $dateStr
     * @param string $format 'dmy' for DD/MM/YYYY or 'mdy' for M/D/YYYY
     */
    private function parseHistoryDate(string $dateStr, string $format = 'dmy'): ?string
    {
        $dateStr = trim($dateStr);
        if (empty($dateStr)) return null;
        
        // Check for Excel empty date (12/31/1899)
        if (strpos($dateStr, '1899') !== false) return null;
        
        try {
            if ($format === 'dmy') {
                // DD/MM/YYYY HH:MM:SS
                $dt = \DateTime::createFromFormat('d/m/Y H:i:s', $dateStr);
                if (!$dt) {
                    $dt = \DateTime::createFromFormat('d/m/Y', $dateStr);
                }
            } else {
                // M/D/YYYY H:MM:SS (Delivery date format)
                $dt = \DateTime::createFromFormat('n/j/Y G:i:s', $dateStr);
                if (!$dt) {
                    $dt = \DateTime::createFromFormat('n/j/Y H:i:s', $dateStr);
                }
                if (!$dt) {
                    $dt = \DateTime::createFromFormat('n/j/Y', $dateStr);
                }
            }
            
            return $dt ? $dt->format('Y-m-d H:i:s') : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    /**
     * Normalize order status for history
     * All orders are either "cancelled" or "delivered" - nothing in between for historical data
     */
    private function normalizeHistoryStatus(string $status): string
    {
        $status = strtolower(trim($status));
        
        // Only cancelled orders stay cancelled, everything else becomes delivered
        $cancelledStatuses = ['cancelled', 'canceled', 'refunded'];
        
        if (in_array($status, $cancelledStatuses)) {
            return 'cancelled';
        }
        
        // Everything else (completed, processing, pending, on-hold, delivered, etc.) = delivered
        return 'delivered';
    }
    
    /**
     * Update delivery dates for history orders from CSV
     * CSV format: Order Number, Delivery Status, Delivery Date
     */
    public function updateHistoryDeliveryDates(Request $request)
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:8192'
        ]);
        
        $file = $request->file('csv_file');
        $rows = array_map('str_getcsv', file($file->getRealPath()));
        
        if (!$rows || count($rows) < 2) {
            return back()->with('history_delivery_result', '<div class="text-red-600">Empty or invalid CSV</div>');
        }
        
        // Remove header row and normalize
        $header = array_map('trim', array_map('strtolower', array_shift($rows)));
        $headerMap = array_flip($header);
        
        $results = [
            'updated' => 0,
            'not_found' => 0,
            'skipped' => 0,
            'errors' => []
        ];
        
        foreach ($rows as $i => $row) {
            try {
                // Support multiple column name formats
                $orderNumber = trim($row[$headerMap['order number'] ?? $headerMap['order_number'] ?? $headerMap['ordernumber'] ?? 0] ?? '');
                $deliveryStatus = strtolower(trim($row[$headerMap['delivery status'] ?? $headerMap['delivery_status'] ?? $headerMap['status'] ?? 1] ?? ''));
                $deliveryDate = trim($row[$headerMap['delivery date'] ?? $headerMap['delivery_date'] ?? $headerMap['deliverydate'] ?? $headerMap['date'] ?? 2] ?? '');
                
                if (empty($orderNumber)) {
                    $results['skipped']++;
                    continue;
                }
                
                // Only update if status is delivered
                if ($deliveryStatus !== 'delivered') {
                    $results['skipped']++;
                    continue;
                }
                
                // Find history order by order_number
                $historyOrder = DB::table('t_crm_history_order')
                    ->where('order_number', $orderNumber)
                    ->first();
                
                if (!$historyOrder) {
                    $results['not_found']++;
                    continue;
                }
                
                // Parse delivery date (M/D/YYYY format from your sheet)
                $parsedDate = $this->parseHistoryDate($deliveryDate, 'mdy');
                
                if (!$parsedDate) {
                    // Try alternative formats
                    $parsedDate = $this->parseHistoryDate($deliveryDate, 'dmy');
                }
                
                if ($parsedDate) {
                    DB::table('t_crm_history_order')
                        ->where('id', $historyOrder->id)
                        ->update([
                            'delivered_at' => $parsedDate,
                            'order_status' => 'delivered',
                            'updated_at' => now()
                        ]);
                    $results['updated']++;
                } else {
                    $results['errors'][] = "Row " . ($i + 2) . ": Could not parse date '{$deliveryDate}' for order {$orderNumber}";
                }
                
            } catch (\Throwable $e) {
                $results['errors'][] = "Row " . ($i + 2) . ": " . $e->getMessage();
            }
        }
        
        $html = '<div class="text-green-700">';
        $html .= '<strong>Update Complete!</strong><br>';
        $html .= "Updated: {$results['updated']}<br>";
        $html .= "Not found: {$results['not_found']}<br>";
        $html .= "Skipped (not delivered): {$results['skipped']}";
        $html .= '</div>';
        
        if (!empty($results['errors'])) {
            $html .= '<div class="mt-2 text-orange-600"><strong>Errors (' . count($results['errors']) . '):</strong><br>';
            $html .= '<div style="max-height: 150px; overflow-y: auto; background: #fffbeb; padding: 8px; border-radius: 4px; margin-top: 4px;">';
            $html .= implode('<br>', array_slice($results['errors'], 0, 50));
            if (count($results['errors']) > 50) {
                $html .= '<br>... and ' . (count($results['errors']) - 50) . ' more';
            }
            $html .= '</div></div>';
        }
        
        return back()->with('history_delivery_result', $html);
    }
    
    /**
     * Clean phone number - take last 10 digits
     */
    private function cleanPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        return substr($digits, -10);
    }
    
    /**
     * Clean amount - remove commas and convert to float
     */
    private function cleanAmount(string $amount): float
    {
        $amount = str_replace([',', ' '], '', trim($amount));
        return is_numeric($amount) ? floatval($amount) : 0;
    }
    
    /**
     * Normalize payment method
     */
    private function normalizePaymentMethod(string $method): string
    {
        $method = strtolower(trim($method));
        
        $map = [
            'cod' => 'cash_on_delivery',
            'cash on delivery' => 'cash_on_delivery',
            'bacs' => 'bank_transfer',
            'bank transfer' => 'bank_transfer',
            'direct bank transfer' => 'bank_transfer',
            'card' => 'card',
            'stripe' => 'online',
            'paypal' => 'online',
        ];
        
        return $map[$method] ?? ($method ?: 'cash');
    }

    public function importRiderAssignments(Request $request)
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:4096'
        ]);

        $file = $request->file('csv_file');
        $rows = array_map('str_getcsv', file($file->getRealPath()));
        if (!$rows || count($rows) < 2) {
            return back()->with('rider_import_result', '<div class="text-red-600">Empty or invalid CSV</div>');
        }
        // Remove header row
        $header = array_map('trim', array_shift($rows));
        // Normalize header names (accept multiple formats)
        $map = array_flip(array_map('strtolower', $header));

        $ok = 0; $skipped = 0; $errors = []; $missingOrders = []; $missingRiders = [];
        foreach ($rows as $i => $row) {
            try {
                $data = [];
                // Accept multiple column name formats
                $data['order_number'] = $row[$map['order_number'] ?? $map['order number'] ?? -1] ?? null;
                $data['rider_name']   = $row[$map['rider_name'] ?? $map['rider name'] ?? $map['delivery_rider'] ?? $map['delivery rider'] ?? -1] ?? null;
                $data['rider_phone']  = $row[$map['rider_phone'] ?? $map['rider phone'] ?? -1] ?? null;
                $data['assigned_at']  = $row[$map['assigned_at'] ?? $map['assigned at'] ?? $map['date'] ?? -1] ?? null;

                // Trim whitespace
                $data['order_number'] = trim($data['order_number'] ?? '');
                $data['rider_name'] = trim($data['rider_name'] ?? '');

                if (!$data['order_number'] || (!$data['rider_name'] && !$data['rider_phone'])) { 
                    $errors[] = 'Row '.($i+2).': Missing order_number or rider info';
                    continue; 
                }

                // Resolve order (non-shopify only)
                $order = DB::table('t_crm_prod_order')
                    ->where(function($q){ $q->whereNull('external_source')->orWhere('external_source','!=','shopify'); })
                    ->where('order_number', $data['order_number'])
                    ->first();
                if (!$order) { 
                    $missingOrders[] = $data['order_number'];
                    continue; 
                }

                // Clean rider name (remove suffixes like "- indrive", "- Indri", etc.)
                $cleanRiderName = $this->cleanEmployeeName($data['rider_name']);

                // Resolve rider user id using smart matching (same as attendance import)
                $rider = $this->findUserByName($cleanRiderName);
                
                if (!$rider) { 
                    $missingRiders[] = $data['rider_name'] . ' (cleaned: ' . $cleanRiderName . ')';
                    continue; 
                }

                // Use model method for assignment
                $model = OrderModel::find($order->id);
                $assignedAt = $data['assigned_at'] ? new \DateTime($data['assigned_at']) : null;
                $success = $model && $model->assignRider((int)$rider->id, 'CSV import', null, $assignedAt);
                
                if ($success) {
                    $ok++;
                } else {
                    $errors[] = 'Row '.($i+2).': Failed to assign rider (order: '.$data['order_number'].')';
                }
            } catch (\Throwable $e) {
                $errors[] = 'Row '.($i+2).': '.$e->getMessage();
            }
        }

        $html = '<div class="text-green-700">Imported: '.$ok.' row(s).</div>';
        if ($missingOrders) {
            $html .= '<div class="mt-2 text-orange-600"><strong>Orders not found (non-Shopify):</strong><br>'.implode(', ', array_unique($missingOrders)).'</div>';
        }
        if ($missingRiders) {
            $html .= '<div class="mt-2 text-orange-600"><strong>Riders not found in users:</strong><br>'.implode(', ', array_unique($missingRiders)).'</div>';
        }
        if ($errors) {
            $html .= '<div class="mt-2 text-red-600"><strong>Processing errors:</strong><br>'.implode('<br>', array_map('htmlspecialchars',$errors)).'</div>';
        }
        return back()->with('rider_import_result', $html);
    }

    public function importAttendance(Request $request)
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:8192'
        ]);

        $rows = array_map('str_getcsv', file($request->file('csv_file')->getRealPath()));
        if (!$rows || count($rows) < 2) {
            return back()->with('attendance_import_result', '<div class="text-red-600">Empty or invalid CSV</div>');
        }
        $header = array_map('trim', array_shift($rows));
        $map = array_flip(array_map('strtolower', $header));

        $ok = 0; $updated = 0; $skipped = 0; $errors = []; $missingEmployees = [];
        $loggedInUserId = auth()->id() ?? 1; // Default to admin if not authenticated
        
        foreach ($rows as $i => $row) {
            try {
                $date = $row[$map['date'] ?? -1] ?? null;
                $employee = $row[$map['employee'] ?? -1] ?? ($row[$map['employee_name'] ?? -1] ?? null);
                $loginTime = $row[$map['login time'] ?? -1] ?? ($row[$map['login_time'] ?? -1] ?? null);
                $logoutTime = $row[$map['log out time'] ?? -1] ?? ($row[$map['logout time'] ?? -1] ?? ($row[$map['logout_time'] ?? -1] ?? null));
                $loginLoc = $row[$map['login location'] ?? -1] ?? null;
                $logoutLoc = $row[$map['logout location'] ?? -1] ?? null;
                $device = $row[$map['device id'] ?? -1] ?? ($row[$map['device_id'] ?? -1] ?? null);
                $meterStart = $row[$map['meter start'] ?? -1] ?? null;
                $meterEnd = $row[$map['meter end'] ?? -1] ?? null;
                $picStart = $row[$map['picture start'] ?? -1] ?? null;
                $picEnd = $row[$map['picture end'] ?? -1] ?? null;

                if (!$date || !$employee) { 
                    $errors[] = 'Row '.($i+2).': Missing date or employee';
                    continue; 
                }

                // Clean employee name (remove suffixes like "- indrive", extra spaces, etc.)
                $cleanName = $this->cleanEmployeeName($employee);

                // Try multiple matching strategies
                $user = $this->findUserByName($cleanName);
                
                if (!$user) { 
                    $missingEmployees[] = $employee . ' (cleaned: ' . $cleanName . ')';
                    continue; 
                }

                // Parse lat,lng from a single cell like "33.7, 73.0"
                [$loginLat,$loginLng] = $this->splitLatLng($loginLoc);
                [$logoutLat,$logoutLng] = $this->splitLatLng($logoutLoc);

                // Parse date properly
                $attendanceDate = date('Y-m-d', strtotime($date));
                
                // Use updateOrInsert to avoid duplicates
                $existing = DB::table('t_ops_attendance')
                    ->where('user_id', $user->id)
                    ->where('attendance_date', $attendanceDate)
                    ->exists();

                DB::table('t_ops_attendance')->updateOrInsert(
                    [
                        'user_id' => $user->id,
                        'attendance_date' => $attendanceDate
                    ],
                    [
                        'login_time' => $loginTime ?: null,
                        'login_lat' => $loginLat,
                        'login_lng' => $loginLng,
                        'logout_time' => $logoutTime ?: null,
                        'logout_lat' => $logoutLat,
                        'logout_lng' => $logoutLng,
                        'device_id' => $device,
                        'meter_start' => is_numeric($meterStart) ? (int)$meterStart : null,
                        'meter_end' => is_numeric($meterEnd) ? (int)$meterEnd : null,
                        'picture_start' => $picStart,
                        'picture_end' => $picEnd,
                        'notes' => 'Legacy CSV import',
                        'created_at' => $existing ? DB::raw('created_at') : now(),
                        'created_by' => $existing ? DB::raw('created_by') : $loggedInUserId,
                        'updated_by' => $loggedInUserId,
                        'updated_at' => now()
                    ]
                );

                // Stamp the shift snapshot for the imported day (non-fatal).
                try {
                    $srs = new \App\Services\ShiftResolutionService();
                    $srs->stampAttendanceSnapshot($user->id, $attendanceDate);
                    // Oct-2026: an import that rewrote a reviewed day retires that review.
                    $srs->supersedeReviewsIfChanged($user->id, $attendanceDate, 'the day was overwritten by a CSV import');
                } catch (\Exception $snapErr) {
                    \Log::warning('CSV attendance import: snapshot failed (non-fatal)', [
                        'user_id' => $user->id, 'date' => $attendanceDate, 'error' => $snapErr->getMessage(),
                    ]);
                }

                if ($existing) {
                    $updated++;
                } else {
                    $ok++;
                }
                
            } catch (\Throwable $e) {
                $errors[] = 'Row '.($i+2).': '.$e->getMessage();
            }
        }

        $html = '<div class="text-green-700">';
        $html .= '<strong>Import Complete!</strong><br>';
        $html .= 'New records: '.$ok.'<br>';
        $html .= 'Updated records: '.$updated.'<br>';
        $html .= 'Total processed: '.($ok + $updated);
        $html .= '</div>';
        
        if ($missingEmployees) {
            $html .= '<div class="mt-2 text-orange-600"><strong>Employees not found in users ('.count(array_unique($missingEmployees)).'):</strong><br>';
            $html .= '<div style="max-height: 200px; overflow-y: auto; background: #fffbeb; padding: 8px; border-radius: 4px; margin-top: 4px;">';
            $html .= implode('<br>', array_unique($missingEmployees));
            $html .= '</div></div>';
        }
        if ($errors) {
            $html .= '<div class="mt-2 text-red-600"><strong>Processing errors ('.count($errors).'):</strong><br>';
            $html .= '<div style="max-height: 200px; overflow-y: auto; background: #fef2f2; padding: 8px; border-radius: 4px; margin-top: 4px;">';
            $html .= implode('<br>', array_map('htmlspecialchars',$errors));
            $html .= '</div></div>';
        }
        return back()->with('attendance_import_result', $html);
    }

    // Helper to clean employee names
    private function cleanEmployeeName($name)
    {
        // Remove common suffixes
        $name = preg_replace('/\s*-\s*(indrive|indriver|indri)/i', '', $name);
        
        // Remove extra spaces
        $name = preg_replace('/\s+/', ' ', $name);
        
        // Trim
        $name = trim($name);
        
        return $name;
    }

    // Helper to find user by name with multiple strategies
    // Searches ALL users (active and inactive) - historical data may reference inactive users
    private function findUserByName($name)
    {
        // Strategy 1: Exact match
        $user = DB::table('t_sys_user')->where('fullname', $name)->first();
        if ($user) return $user;

        // Strategy 2: Case-insensitive exact match
        $user = DB::table('t_sys_user')->whereRaw('LOWER(fullname) = ?', [strtolower($name)])->first();
        if ($user) return $user;

        // Strategy 3: LIKE match (starts with)
        $user = DB::table('t_sys_user')->where('fullname', 'like', $name.'%')->first();
        if ($user) return $user;

        // Strategy 4: Contains match
        $user = DB::table('t_sys_user')->where('fullname', 'like', '%'.$name.'%')->first();
        if ($user) return $user;

        return null;
    }

    private function splitLatLng($cell)
    {
        if (!$cell) return [null,null];
        if (strpos($cell, ',') !== false) {
            $parts = array_map('trim', explode(',', $cell));
            return [is_numeric($parts[0]??null)?(float)$parts[0]:null, is_numeric($parts[1]??null)?(float)$parts[1]:null];
        }
        return [null,null];
    }
}


