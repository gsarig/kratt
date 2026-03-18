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
		add_action( 'wp_abilities_api_init', [ InsertBlockAbility::class, 'register' ] );
		add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
		add_action( 'activated_plugin', [ Catalog\BlockCatalog::class, 'scan' ] );
		add_action( 'after_switch_theme', [ Catalog\BlockCatalog::class, 'scan' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( KRATT_FILE ), [ $this, 'add_action_links' ] );
	}

	public function add_action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=kratt' ) ),
			esc_html__( 'Settings', 'kratt' )
		);
		array_unshift( $links, $settings_link );
		return $links;
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

		$custom_blocks = array_filter( $catalog, static fn( $b ) => ( $b['source'] ?? 'custom' ) === 'custom' );
		$core_blocks   = array_filter( $catalog, static fn( $b ) => ( $b['source'] ?? 'custom' ) === 'core' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Kratt', 'kratt' ); ?></h1>

			<p>
				<?php
				if ( $scanned_at ) {
					printf(
						/* translators: %1$d: block count, %2$s: last scanned date */
						esc_html__( '%1$d blocks in catalog. Last scanned: %2$s', 'kratt' ),
						count( $catalog ),
						esc_html( $scanned_at )
					);
				} else {
					esc_html_e( 'No catalog yet. Run a scan to get started.', 'kratt' );
				}
				?>
				&nbsp;
				<button type="button" id="kratt-rescan" class="button button-secondary">
					<?php esc_html_e( 'Rescan Blocks', 'kratt' ); ?>
				</button>
				<span id="kratt-rescan-status" style="margin-left:8px;"></span>
			</p>

			<?php if ( $catalog ) : ?>

				<?php if ( $custom_blocks ) : ?>
					<h2><?php esc_html_e( 'Custom Blocks', 'kratt' ); ?></h2>
					<?php $this->render_block_table( $custom_blocks ); ?>
				<?php endif; ?>

				<details <?php echo $custom_blocks ? '' : 'open'; ?>>
					<summary style="cursor:pointer; margin-bottom:8px;">
						<strong>
							<?php
							printf(
								/* translators: %d: number of core blocks */
								esc_html__( 'Core Blocks (%d)', 'kratt' ),
								count( $core_blocks )
							);
							?>
						</strong>
					</summary>
					<?php $this->render_block_table( $core_blocks ); ?>
				</details>

			<?php endif; ?>
		</div>

		<script>
		document.getElementById( 'kratt-rescan' ).addEventListener( 'click', function () {
			const status = document.getElementById( 'kratt-rescan-status' );
			status.textContent = '<?php echo esc_js( __( 'Scanning…', 'kratt' ) ); ?>';
			fetch( '<?php echo esc_url( rest_url( 'kratt/v1/catalog/rescan' ) ); ?>', {
				method: 'POST',
				headers: {
					'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>',
					'Content-Type': 'application/json',
				},
			} )
			.then( r => r.json() )
			.then( data => {
				status.textContent = data.message || '<?php echo esc_js( __( 'Done.', 'kratt' ) ); ?>';
				setTimeout( () => location.reload(), 1000 );
			} )
			.catch( () => {
				status.textContent = '<?php echo esc_js( __( 'Error. Please try again.', 'kratt' ) ); ?>';
			} );
		} );
		</script>
		<?php
	}

	private function render_block_table( array $blocks ): void {
		?>
		<table class="widefat striped" style="margin-bottom:24px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Block', 'kratt' ); ?></th>
					<th><?php esc_html_e( 'Slug', 'kratt' ); ?></th>
					<th><?php esc_html_e( 'Description', 'kratt' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $blocks as $name => $block ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $block['title'] ?? $name ); ?></strong></td>
						<td><code><?php echo esc_html( $name ); ?></code></td>
						<td><?php echo esc_html( $block['description'] ?? '' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
