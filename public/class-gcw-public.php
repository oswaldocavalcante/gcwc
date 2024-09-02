<?php

require_once GCW_ABSPATH . 'integrations/gestaoclick/class-gcw-gc-orcamento.php';
require_once GCW_ABSPATH . 'integrations/gestaoclick/class-gcw-gc-cliente.php';

require_once GCW_ABSPATH . 'public/views/shortcodes/class-gcw-shortcode-quote.php';
require_once GCW_ABSPATH . 'public/views/shortcodes/class-gcw-shortcode-checkout.php';

use Dompdf\Dompdf;
use Dompdf\Options;

class GCW_Public
{
	private $quote;
	private $checkout;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string    $plugin_name       The name of this plugin.
	 * @param      string    $version    The version of this plugin.
	 */
	public function __construct()
	{
		$this->quote 	= new GCW_Shortcode_Quote();
		$this->checkout = new GCW_Shortcode_Checkout();
	}

	public function session_start()
	{
		if (is_user_logged_in() || is_admin()){
			return;
		}
		if (isset(WC()->session)){
			if (!WC()->session->has_session()){
				WC()->session->set_customer_session_cookie(true);
			}
		}
	}

	// Adicionar a query var para o endpoint
	function add_quote_query_vars($vars)
	{
		$vars[] = 'orcamento';
		return $vars;
	}

	public function include_template_quote($template)
	{
		if (is_singular('orcamento')) {
			// Caminho para o template no diretório do plugin
			$template = GCW_ABSPATH . 'public/views/templates/single-quote.php';
			if (file_exists($template)) {
				return $template;
			}
		}

		return $template;
	}

	// Função para adicionar 'Orçamentos' ao menu de conta
	function add_orcamentos_to_account_menu($items)
	{
		$items = array_slice($items, 0, 2, true) + // Mantém o primeiro item
			array('orcamentos' => 'Orçamentos') + // Adiciona 'Orçamentos'
			array_slice($items, 2, NULL, true); // Mantém o resto

		return $items;
	}

	// Exibe o conteúdo do endpoint 'orcamentos' em WC myaccount
	function orcamentos_endpoint_content()
	{
		wc_get_template('wc-myaccount-quotes.php', array(), 'quotes', GCW_ABSPATH . 'public/views/templates/');
	}

	public function shortcode_quote()
	{
		wp_enqueue_style('gcw-shortcode-quote', GCW_URL . 'public/assets/css/gcw-public.css', array(), GCW_VERSION, 'all');
		wp_enqueue_script('gcw-shortcode-quote', GCW_URL . 'public/assets/js/gcw-shortcode-quote.js', array('jquery'), GCW_VERSION, false);
		wp_localize_script('gcw-shortcode-quote', 'gcw_quote_ajax_object', array(
			'url'   => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('gcw_quote_nonce'),
		));
		
		return $this->quote->render();
	}

	public function shortcode_checkout()
	{
		wp_enqueue_style('gcw-shortcode-quote', GCW_URL . 'public/assets/css/gcw-public.css', array(), GCW_VERSION, 'all');
		wp_enqueue_style('gcw-shortcode-checkout', GCW_URL . 'public/assets/css/gcw-shortcode-checkout.css', array(), GCW_VERSION, 'all');
		wp_enqueue_script('gcw-shortcode-checkout', GCW_URL . 'public/assets/js/gcw-shortcode-checkout.js', array('jquery'), GCW_VERSION, false);
		wp_localize_script('gcw-shortcode-checkout', 'gcw_quote_ajax_object', array(
			'url'   => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('gcw_quote_nonce'),
		));

		return $this->checkout->render();
	}

	public function add_to_quote_button()
	{
		$product = wc_get_product(get_the_ID());

		if ($product){
			if ($product->get_stock_status() == 'onbackorder') 
			{
				wp_enqueue_style('gcw-add-to-quote-button', plugin_dir_url(__FILE__) . 'assets/css/gcw-public.css', array(), GCW_VERSION, 'all');
				wp_enqueue_script('gcw-add-to-quote-button', plugin_dir_url(__FILE__) . 'assets/js/gcw-add-to-quote-button.js', array('jquery'), GCW_VERSION, false);
				echo '<a id="gcw_add_to_quote_button" class="disabled" product_id="' . get_the_ID() . '">Adicionar ao orçamento</a>';

				// Diferencia o script para produtos variáveis e simples
				if ($product->has_child()) {
					wp_enqueue_script('gcw-add-to-quote-variation', plugin_dir_url(__FILE__) . 'assets/js/gcw-add-to-quote-variation.js', array('jquery'), GCW_VERSION, true);
					wp_localize_script('gcw-add-to-quote-variation', 'gcw_add_to_quote_variation', array(
						'url' 	=> admin_url('admin-ajax.php'),
						'nonce' => wp_create_nonce('gcw_add_to_quote_variation')
					));
				} else {
					wp_enqueue_script('gcw-add-to-quote-simple', plugin_dir_url(__FILE__) . 'assets/js/gcw-add-to-quote-simple.js', array('jquery'), GCW_VERSION, true);
					wp_localize_script('gcw-add-to-quote-simple', 'gcw_add_to_quote_simple', array(
						'url' 	=> admin_url('admin-ajax.php'),
						'nonce' => wp_create_nonce('gcw_add_to_quote_simple')
					));
				}
			}
		}
	}

	public function ajax_create_spec_sheet()
	{
		// Verificar nonce
		if (!check_ajax_referer('gcw_spec_sheet_nonce', 'nonce', false)) {
			wp_die('Erro de segurança');
		}

		$product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
		$quote_id = isset($_GET['quote_id']) ? intval($_GET['quote_id']) : 0;

		if (!$product_id || !$quote_id) {
			wp_die('Parâmetros inválidos');
		}

		$product = wc_get_product($product_id);
		$quote_items = get_post_meta($quote_id, 'items', true);
		$item = array_filter($quote_items, function ($item) use ($product_id) {
			return $item['product_id'] == $product_id;
		});
		$item = reset($item);

		// Configurar cabeçalhos para exibir o PDF no navegador
		header('Content-Type: application/pdf');
		header('Content-Disposition: inline; filename="ficha_tecnica.pdf"');
		header('Cache-Control: private, max-age=0, must-revalidate');
		header('Pragma: public');

		require_once(GCW_ABSPATH . 'vendor/autoload.php');
		$options = new Options();
		$options->set('isHtml5ParserEnabled', true);
		$options->set('isPhpEnabled', true);
		$options->set('isRemoteEnabled', true);
		$options->set('chroot', wp_upload_dir()); // Permissão para acessar imagens da pasta uploads

		$dompdf = new Dompdf($options);

		ob_start();
		include(GCW_ABSPATH . 'public/views/templates/spec-sheet.php');
		$html = ob_get_clean();

		$dompdf->loadHtml($html);
		$dompdf->render();
		$dompdf->stream("ficha-tecnica.pdf", array("Attachment" => false));

		exit;
	}
}