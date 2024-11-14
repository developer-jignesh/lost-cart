<?php
namespace BWFAN\Exporter;

use BWFAN\Exporter\Base;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class Lost_Carts extends Base {
    public $export_id = 0;
    private $user_id = 0;
    private $current_pos = 0;
    private $batch_limit = 10;
    private $db_export_row = [];
    private $start_time = 0;
    private $export_meta = [];
    private $halt = 0;

    public function __construct() {
        $this->type = 'lost';
    }

    public function maybe_insert_data_in_table() {
        error_log("Starting maybe_insert_data_in_table");

        if (!file_exists(BWFCRM_EXPORT_DIR)) {
            if (!wp_mkdir_p(BWFCRM_EXPORT_DIR)) {
                error_log("Failed to create export directory at " . BWFCRM_EXPORT_DIR);
                return 0;
            }
        }

        $file_name = 'lost-cart-export-' . time() . '.csv';
        $file_path = BWFCRM_EXPORT_DIR . '/' . $file_name;

        $file = fopen($file_path, "w");
        if (!$file) {
            return 0;
        }

        $headers = ['email', 'user_id', 'created_time', 'items', 'total', 'currency', 'checkout_data', 'checkout_page_id'];
        fputcsv($file, $headers);
        fclose($file);

        $count = \BWFAN_Recoverable_Carts::get_abandoned_carts('', '', '', '', '', true)['total_count'];

        $data = [
            'offset' => 0,
            'type' => 2,
            'status' => 1,
            'count' => $count,
            'meta' => wp_json_encode(['title' => 'lost', 'file' => $file_name]),
            'created_date' => current_time('mysql', 1),
            'last_modified' => current_time('mysql', 1)
        ];
        \BWFAN_Model_Import_Export::insert($data);
        $this->export_id = \BWFAN_Model_Import_Export::insert_id();

        $this->db_export_row = \BWFAN_Model_Import_Export::get($this->export_id);
        $this->export_meta = json_decode($this->db_export_row['meta'], true);


        return $this->export_id;
    }

    public function handle_export($user_id, $export_id = 0) {
		$this->export_id = $export_id;
		$this->user_id = $user_id;
	
		// Fetch export data row and decode meta
		if (is_array($this->db_export_row) && !empty($this->db_export_row) && absint($this->db_export_row['id']) === absint($export_id)) {
			return;
		}
	
		$this->db_export_row = \BWFAN_Model_Import_Export::get($this->export_id);
		$this->export_meta = !empty($this->db_export_row['meta']) ? json_decode($this->db_export_row['meta'], true) : [];
	
	
		if (empty($this->export_meta['file'])) {
			return;
		}
	
		$file_path = BWFCRM_EXPORT_DIR . '/' . $this->export_meta['file'];
		$this->current_pos = absint($this->db_export_row['offset']);
	
		$this->start_time = time();
		$carts = \BWFAN_Recoverable_Carts::get_abandoned_carts('', '', $this->current_pos, $this->batch_limit, '', false);
	
		// Start the batch export using while loop
		while (!empty($carts) && ((time() - $this->start_time) < 30) && !\BWFCRM_Common::memory_exceeded() && $this->halt === 0) {
			
			
			$this->export_to_csv($file_path, $carts);
			$this->current_pos += count($carts);
	
			$this->update_offset();
	
			if ($this->get_percent_completed() >= 100) {
				$this->end_export(2, __('Export completed successfully', 'wp-marketing-automations-pro'));
				return;
			}
	
			// Fetch the next batch of carts
			$carts = \BWFAN_Recoverable_Carts::get_abandoned_carts('', '', $this->current_pos, $this->batch_limit, '2', false);
	
			// End the export if there are no more carts to process
			if (empty($carts)) {
				$this->end_export(2, __('Export completed - no more carts', 'wp-marketing-automations-pro'));
				return;
			}
		}
	}
	
	private function export_to_csv($file_path, $carts) {
	
		$file = fopen($file_path, "a");
		if (!$file) {
			return;
		}
	
		foreach ($carts as $cart) {
			if (!is_object($cart)) {
				continue;
			}
	
			$email = $this->sanitize_field($cart->email ?? '');
			$user_id = $this->sanitize_field($cart->user_id ?? '');
			$created_time = $this->sanitize_field($cart->created_time ?? '');
			$items = is_string($cart->items) ? $cart->items : wp_json_encode($cart->items);  
			$total = $this->sanitize_field($cart->total ?? '');
			$currency = $this->sanitize_field($cart->currency ?? '');
			$checkout_data = is_string($cart->checkout_data) ? $cart->checkout_data : wp_json_encode($cart->checkout_data);
			$checkout_page_id = $this->sanitize_field($cart->checkout_page_id ?? '');
	
	
			$row_data = [$email, $user_id, $created_time, $items, $total, $currency, $checkout_data, $checkout_page_id];
			
			fputcsv($file, $row_data);
		}
	
		fclose($file);
	}
	
    private function update_offset() {
        \BWFAN_Model_Import_Export::update(["offset" => $this->current_pos], ['id' => absint($this->export_id)]);
    }

    private function get_percent_completed() {
        $percent = !empty($this->db_export_row['count']) ? min(round(($this->current_pos / $this->db_export_row['count']) * 100), 100) : 0;
        return $percent;
    }

    public function end_export($status = 3, $status_message = '') {

        BWFAN_Core()->exporter->unschedule_export_action([
            'type' => $this->type,
            'user_id' => $this->user_id,
            'export_id' => $this->export_id
        ]);

        if (empty($status_message) && $status === 3) {
            $status_message = 'Cart export completed. Export ID: ' . $this->export_id;
        }

        $this->export_meta['status_msg'] = $status_message;
        \BWFAN_Model_Import_Export::update([
            "status" => $status,
            "meta" => wp_json_encode($this->export_meta)
        ], ['id' => absint($this->export_id)]);
    }

    private function sanitize_field($field) {
        return is_null($field) ? 'N/A' : (is_array($field) || is_object($field) ? wp_json_encode($field) : sanitize_text_field($field));
    }
}

// Register the Cart exporter
BWFAN_Core()->exporter->register_exporter('lost_carts', 'BWFAN\Exporter\Lost_Carts');
