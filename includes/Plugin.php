<?php
namespace MediaStorage\JetFormBuilder;

use MediaStorage\JetFormBuilder\Settings\SettingsRepository;
use MediaStorage\JetFormBuilder\Settings\SettingsTab;
use MediaStorage\JetFormBuilder\Storage\FormSettings;
use MediaStorage\JetFormBuilder\Storage\Manager as StorageManager;
use MediaStorage\JetFormBuilder\Storage\ProviderRegistry;
use MediaStorage\JetFormBuilder\Rest\DropboxRoutes;
use MediaStorage\JetFormBuilder\Rest\GoogleDriveRoutes;
use Jet_Form_Builder\Admin\Tabs_Handlers\Tab_Handler_Manager;
use YahnisElsts\PluginUpdateChecker\v5p0\PucFactory;

class Plugin {

    public const META_DISABLE = MSJFB_DISABLE_FORM_META;
	public const META_STORAGE = '_msjfb_storage_settings';
	public const FORM_POST_TYPE = 'jet-form-builder';

    private static ?self $instance = null;

    private string $slug = 'media-storage-for-jetformbuilder';

    private string $plugin_file;

	private string $version;

	private StorageManager $storage_manager;

	private function __construct( string $plugin_file ) {
		$this->plugin_file = $plugin_file;
		$this->version     = get_file_data( $plugin_file, array( 'Version' => 'Version' ) )['Version'] ?? '0.0';

		$this->init_hooks();
		$this->init_updater();
	}

	public static function instance( ?string $plugin_file = null ): self {
		if ( null === self::$instance ) {
			if ( null === $plugin_file ) {
				throw new \RuntimeException( 'Plugin file path is required on first initialization.' );
			}

			self::$instance = new self( $plugin_file );
		}

		return self::$instance;
	}

	public function slug(): string {
		return $this->slug;
	}

	public function version(): string {
		return $this->version;
	}

	public function path( string $path = '' ): string {
		return plugin_dir_path( $this->plugin_file ) . ltrim( $path, '/' );
	}

	public function url( string $path = '' ): string {
		return plugin_dir_url( $this->plugin_file ) . ltrim( $path, '/' );
	}

	private function init_hooks(): void {
		add_filter(
			'plugin_action_links_' . plugin_basename( $this->plugin_file ),
			array( $this, 'add_action_links' )
		);

        add_filter(
            'jet-form-builder/register-tabs-handlers',
            array( $this, 'register_tabs' )
        );

		add_action( 'init', array( $this, 'register_post_meta' ) );

		add_action(
			'jet-form-builder/editor-assets/before',
			array( $this, 'enqueue_form_editor_assets' )
		);
		add_action(
			'rest_api_init',
			array( $this, 'register_rest_routes' )
		);

        $this->storage_manager = new StorageManager();
        $this->storage_manager->register();
    }

	public function register_post_meta(): void {
		register_post_meta(
			self::FORM_POST_TYPE,
			self::META_DISABLE,
			array(
				'type'         => 'string',
				'single'       => true,
				'default'      => wp_json_encode(
					array(
						'disabled' => true,
					)
				),
				'show_in_rest' => array(
					'schema' => array(
						'type' => 'string',
					),
				),
				'auth_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		register_post_meta(
			self::FORM_POST_TYPE,
			self::META_STORAGE,
			array(
				'type'         => 'string',
				'single'       => true,
				'default'      => FormSettings::defaults_json(),
				'show_in_rest' => array(
					'schema' => array(
						'type' => 'string',
					),
				),
				'auth_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
    }

	public function enqueue_form_editor_assets(): void {
		$script_rel_path = 'assets/js/form-editor.js';
		$script_path     = $this->path( $script_rel_path );

        if ( ! file_exists( $script_path ) ) {
            return;
        }

        $handle  = $this->slug() . '-form-editor';
        $version = (string) filemtime( $script_path );

		wp_enqueue_script(
			$handle,
			$this->url( $script_rel_path ),
			array( 'wp-hooks', 'wp-components', 'wp-element', 'wp-data', 'wp-i18n', 'jet-fb-data' ),
			$version,
			true
		);

		wp_localize_script(
			$handle,
			'MSJFBFormSettings',
			array(
				'metaKey'      => self::META_STORAGE,
				'defaultState' => FormSettings::defaults_json(),
				'providers'    => ProviderRegistry::editor_providers(),
				'folderTemplateDefault' => SettingsRepository::default_folder_template(),
				'maxFilesizeDefault'    => SettingsRepository::max_filesize_mb(),
				'deleteOriginalDefault' => SettingsRepository::delete_original_enabled(),
				'globalAllowedFileTypes' => SettingsRepository::allowed_file_types(),
				'mimeTypeSuggestions'   => array_values( get_allowed_mime_types() ),
				'labels'  => array(
					'title'           => __( 'Media Storage', 'media-storage-for-jetformbuilder' ),
					'usageLabel'      => __( 'Storage usage', 'media-storage-for-jetformbuilder' ),
					'usageDisabled'   => __( 'Do not use external storage', 'media-storage-for-jetformbuilder' ),
					'usageEnabled'    => __( 'Use external storage', 'media-storage-for-jetformbuilder' ),
					'folderLabel'     => __( 'Folder route', 'media-storage-for-jetformbuilder' ),
					'folderHelp'      => __( 'Provide a custom directory or type "default" to inherit the global template. Macros: %formid%, %formname%, %currentdate%, %currentyear%, %currentmonth%, %currentday%, %fieldslug%.', 'media-storage-for-jetformbuilder' ),
					'sizeLabel'       => __( 'Max file size (MB)', 'media-storage-for-jetformbuilder' ),
					'sizeHelp'        => __( 'Leave empty to inherit the global limit. Use 0 or -1 for unlimited uploads. Supports decimals like 1.5 or 0,5.', 'media-storage-for-jetformbuilder' ),
					'sizeHelpGlobal'  => __( 'Current global limit: %s MB.', 'media-storage-for-jetformbuilder' ),
					'sizeHelpGlobalUnlimited' => __( 'Current global limit: unlimited.', 'media-storage-for-jetformbuilder' ),
					'noProviders'     => __( 'Enable at least one provider inside the Media Storage settings page to configure per-form behavior.', 'media-storage-for-jetformbuilder' ),
					'folderPlaceholder' => 'default',
				),
            )
        );

        if ( function_exists( 'wp_set_script_translations' ) ) {
            wp_set_script_translations( $handle, 'media-storage-for-jetformbuilder' );
        }
    }

	public function add_action_links( array $links ): array {
		$settings_url = admin_url( 'edit.php?post_type=jet-form-builder&page=jfb-settings#media-storage-settings-tab' );

		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( $settings_url ), esc_html__( 'Configure', 'media-storage-for-jetformbuilder' ) )
		);

		return $links;
	}

	public function register_tabs( array $tabs ): array {
		$tabs[] = new SettingsTab();

		return $tabs;
	}

	public function register_rest_routes(): void {
		( new DropboxRoutes() )->register();
		( new GoogleDriveRoutes() )->register();
	}

	private function init_updater(): void {
		if ( ! class_exists( PucFactory::class ) ) {
			return;
		}

		PucFactory::buildUpdateChecker(
			'https://pluginupdater.hellodevs.dev/plugins/media-storage-for-jetformbuilder.json',
			$this->plugin_file,
			'media-storage-for-jetformbuilder'
		);
	}

}
