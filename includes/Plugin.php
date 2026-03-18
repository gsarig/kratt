<?php

declare( strict_types=1 );

namespace Kratt;

use Kratt\Abilities\InsertBlockAbility;
use Kratt\Editor\Sidebar;
use Kratt\REST\CatalogController;
use Kratt\REST\ComposeController;

class Plugin {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function init(): void {
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		add_action( 'enqueue_block_editor_assets', [ Sidebar::class, 'enqueue' ] );
		add_action( 'init', [ InsertBlockAbility::class, 'register' ] );
		add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
		add_action( 'activated_plugin', [ Catalog\BlockCatalog::class, 'scan' ] );
		add_action( 'after_switch_theme', [ Catalog\BlockCatalog::class, 'scan' ] );
	}

	public function register_rest_routes(): void {
		( new ComposeController() )->register_routes();
		( new CatalogController() )->register_routes();
	}

	public function register_admin_menu(): void {
		add_options_page(
			__( 'Kratt', 'kratt' ),
			__( 'Kratt', 'kratt' ),
			'manage_options',
			'kratt',
			[ $this, 'render_settings_page' ]
		);
	}

	public function render_settings_page(): void {
		$catalog    = Catalog\BlockCatalog::get();
		$scanned_at = Settings\Settings::get_catalog_scanned_at();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Kratt', 'kratt' ); ?></h1>

			<h2><?php esc_html_e( 'Block Catalog', 'kratt' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %1$d: block count, %2$s: last scanned date */
					esc_html__( '%1$d blocks in catalog. Last scanned: %2$s', 'kratt' ),
					count( $catalog ),
					$scanned_at ? esc_html( $scanned_at ) : esc_html__( 'Never', 'kratt' )
				);
				?>
			</p>

			<form method="post" action="<?php echo esc_url( rest_url( 'kratt/v1/catalog/rescan' ) ); ?>">
				<?php wp_nonce_field( 'wp_rest', '_wpnonce' ); ?>
				<p>
					<button type="button" id="kratt-rescan" class="button button-secondary">
						<?php esc_html_e( 'Rescan Blocks', 'kratt' ); ?>
					</button>
					<span id="kratt-rescan-status" style="margin-left:10px;"></span>
				</p>
			</form>

			<script>
			document.getElementById('kratt-rescan').addEventListener('click', function() {
				const status = document.getElementById('kratt-rescan-status');
				status.textContent = '<?php esc_html_e( 'Scanning…', 'kratt' ); ?>';
				fetch('<?php echo esc_url( rest_url( 'kratt/v1/catalog/rescan' ) ); ?>', {
					method: 'POST',
					headers: {
						'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>',
						'Content-Type': 'application/json',
					},
				})
				.then(r => r.json())
				.then(data => {
					status.textContent = data.message || '<?php esc_html_e( 'Done.', 'kratt' ); ?>';
					setTimeout(() => location.reload(), 1000);
				})
				.catch(() => {
					status.textContent = '<?php esc_html_e( 'Error. Please try again.', 'kratt' ); ?>';
				});
			});
			</script>
		</div>
		<?php
	}
}
