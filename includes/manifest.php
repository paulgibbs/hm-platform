<?php
/**
 * HM Platform Plugin Manifest.
 */

namespace HM\Platform;

/**
 * HM Platform plugin configuration.
 *
 * @return array
 *  $manifest = [
 *    '<plugin-name>' => [
 *      'file'     => (string)   The file to load.
 *      'enabled'  => (bool)     The default state of the plugin. True for active.
 *      'title'    => (string)   Optional human readable name, if set plugin will show in UI.
 *      'loader'   => (callable) Optional custom loading function.
 *      'settings' => (array)    Optional Key value pairs of settings and their default values.
 *      'activate' => (callable) Optional function to be run once on first activation.
 *    ]
 *  ]
 *
 */
function get_plugin_manifest() {
	$manifest = [
		'cavalcade'            => [
			'title'   => 'Cavalcade',
			'file'    => 'plugins/cavalcade/plugin.php',
			'enabled' => true,
			'loader'  => function ( $plugin ) {
				// Load the Cavalcade Runner CloudWatch extension.
				// This is loaded on the Cavalcade-Runner, not WordPress, crazy I know.
				if ( class_exists( 'HM\\Cavalcade\\Runner\\Runner' ) && HM_ENV_TYPE !== 'local' ) {
					require_once ROOT_DIR . '/lib/cavalcade-runner-to-cloudwatch/plugin.php';
				}

				// Load plugin on normal hook.
				add_action(
					'muplugins_loaded', function () use ( $plugin ) {

						// Force DISABLE_WP_CRON for Cavalcade.
						if ( ! defined( 'DISABLE_WP_CRON' ) ) {
							define( 'DISABLE_WP_CRON', true );
						}

						require $plugin['file'];
					}
				);
			},
		],
		'memcached'            => [
			'file'    => 'dropins/wordpress-pecl-memcached-object-cache/object-cache.php',
			'enabled' => get_environment_architecture() === 'ec2',
			'loader'  => function ( $plugin ) {
				add_filter(
					'enable_wp_debug_mode_checks', function ( $wp_debug_enabled ) use ( $plugin ) {
						if ( ! class_exists( 'Memcached' ) ) {
							return $wp_debug_enabled;
						}

						wp_using_ext_object_cache( true );
						require $plugin['file'];

						// Cache must be initted once it's included, else we'll get a fatal.
						wp_cache_init();

						return $wp_debug_enabled;
					}, 0
				); // Make sure this is run before everything else
			},
		],
		'redis'                => [
			'file'    => 'plugins/wp-redis/object-cache.php',
			'enabled' => get_environment_architecture() === 'ecs',
			'loader'  => function ( $plugin ) {
				add_filter(
					'enable_wp_debug_mode_checks', function ( $wp_debug_enabled ) use ( $plugin ) {
						// Don't load if memcached is enabled.
						$config = Config\get_config();
						if ( isset( $config['memcached'] ) && $config['memcached']['enabled'] ) {
							return $wp_debug_enabled;
						}

						wp_using_ext_object_cache( true );

						require ROOT_DIR . '/dropins/wp-redis-predis-client/vendor/autoload.php';
						require ROOT_DIR . '/plugins/wp-redis/wp-redis.php';
						\WP_Predis\add_filters();
						require $plugin['file'];

						// Cache must be initted once it's included, else we'll get a fatal.
						wp_cache_init();

						return $wp_debug_enabled;
					}, 0
				); // Make sure this is run before everything else
			},
		],
		'batcache'             => [
			'title'   => 'Batcache'
			'file'    => 'dropins/batcache/advanced-cache.php',
			'enabled' => true,
			'loader'  => function ( $plugin ) {
				add_filter(
					'enable_wp_debug_mode_checks', function ( $should_load ) use ( $plugin ) {
						if ( ! class_exists( 'Memcached' ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
							return $should_load;
						}

						if ( ! $should_load ) {
							return $should_load;
						}

						// Disable loading advanced-cache.php from content directory.
						add_filter(
							'enable_loading_advanced_cache_dropin', function () {
								return false;
							}
						);

						require $plugin['file'];

						return $should_load;
					}, 5
				); // Load after Memcached/Redis, before everything else
			},
		],
		'xray'                 => [
			'title'   => 'X-Ray',
			'file'    => 'plugins/aws-xray/plugin.php',
			'enabled' => false,
			'loader'  => function ( $plugin ) {
				if ( function_exists( 'xhprof_sample_enable' ) && ( ! defined( 'WP_CLI' ) || ! WP_CLI ) ) {
					// Start sampling.
					global $hm_platform_xray_start_time;
					$hm_platform_xray_start_time = microtime( true );
					ini_set( 'xhprof.sampling_interval', 5000 );
					xhprof_sample_enable();

					// Load DB replacement.
					add_filter(
						'enable_wp_debug_mode_checks', function ( $enable_wp_debug_mode ) {
							require_once ABSPATH . WPINC . '/wp-db.php';
							require ROOT_DIR . '/plugins/aws-xray/inc/class-db.php';

							global $wpdb;
							$wpdb = new XRay\DB( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );

							return $enable_wp_debug_mode;
						}
					);

					// Load main plugin.
					add_action(
						'muplugins_loaded', function () use ( $plugin ) {
							require_once $plugin['file'];
						}
					);
				}
			},
		],
		'healthcheck'          => [
			'file'    => 'plugins/healthcheck/plugin.php',
			'enabled' => true,
		],
		'aws-ses-wp-mail'      => [
			'file'    => 'plugins/aws-ses-wp-mail/aws-ses-wp-mail.php',
			'enabled' => true,
			'loader'  => function ( $plugin ) {
				// Load logger on AWS.
				if ( HM_ENV_TYPE !== 'local' ) {
					require_once ROOT_DIR . '/lib/ses-to-cloudwatch/plugin.php';
				}

				add_action(
					'muplugins_loaded', function () use ( $plugin ) {
						require $plugin['file'];
					}
				);
			},
		],
		'platform-ui'          => [
			'file'    => 'plugins/hm-platform-ui/admin.php',
			'enabled' => false,
		],
		'hm-stack-api'         => [
			'enabled' => true,
			'file'    => 'plugins/hm-stack/hm-stack.php',
		],
		'elasticsearch'        => [
			'file'   => 'lib/elasticsearch-integration.php',
			'loader' => function ( $plugin ) {
				if ( ! defined( 'ELASTICSEARCH_HOST' ) ) {
					return;
				}

				if ( HM_ENV_TYPE === 'local' ) {
					return;
				}

				require_once $plugin['file'];
				ElasticSearch_Integration\bootstrap();
			},
		],
		's3-uploads'           => [
			'title'   => 'S3 Uploads',
			'file'    => 'plugins/s3-uploads/s3-uploads.php',
			'enabled' => true,
		],
		'tachyon'              => [
			'title'    => 'Tachyon',
			'file'     => 'plugins/tachyon/tachyon.php',
			'enabled'  => true,
			'settings' => [
				'smart-cropping' => true,
				'retina'         => false,
			],
		],
		'sitemaps'             => [
			'title' => 'Sitemaps',
			'file'  => 'plugins/msm-sitemap/msm-sitemap.php',
		],
		'related-posts'        => [
			'file'  => 'plugins/hm-related-posts/hm-related-posts.php',
			'title' => 'Related posts',
		],
		'redirects'            => [
			'file'  => 'plugins/hm-redirects/hm-redirects.php',
			'title' => 'Redirects',
		],
		'bylines'              => [
			'file'  => 'plugins/bylines/bylines.php',
			'title' => 'Bylines',
		],
		'elasticpress'         => [
			'file'     => 'plugins/elasticpress/elasticpress.php',
			'title'    => 'ElasticPress',
			'settings' => [
				'network'     => true,
				'autosuggest' => true,
				'admin'       => false,
			],
		],
		'cmb2'                 => [
			'file'  => 'plugins/cmb2/init.php',
			'title' => 'Custom Meta Boxes',
		],
		'extended-cpts'        => [
			'file'  => 'plugins/extended-cpts/extended-cpts.php',
			'title' => 'Extended Custom Post Types & Taxonomies',
		],
		'query-monitor'        => [
			'file'  => 'plugins/query-monitor/query-monitor.php',
			'title' => 'Query Monitor',
		],
		'google-tag-manager'   => [
			'file'     => 'plugins/hm-gtm/hm-gtm.php',
			'title'    => 'Google Tag Manager',
			'settings' => [
				'network-container-id' => null,
				'container-id'         => null,
			],
		],
		'workflows'            => [
			'file'  => 'plugins/workflows/plugin.php',
			'title' => 'Workflows',
		],
		'rekognition'          => [
			'file'     => 'plugins/aws-rekognition/plugin.php',
			'title'    => 'Rekognition',
			'settings' => [
				'labels'      => true,
				'moderation'  => false,
				'faces'       => false,
				'celebrities' => false,
				'text'        => false,
			],
		],
		'smart-media'          => [
			'file'  => 'plugins/smart-media/plugin.php',
			'title' => 'Smart Media',
			'settings' => [
				'justified-library' => true,
				'cropper'           => true,
			],
		],
		'require-login'        => [
			'file'    => 'plugins/hm-require-login/plugin.php',
			'title'   => 'Require Login',
			'enabled' => ! in_array( HM_ENV_TYPE, [ 'local', 'production' ], true ),
		],
	];

	return $manifest;
}
