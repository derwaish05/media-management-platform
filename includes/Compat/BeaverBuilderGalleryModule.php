<?php

declare(strict_types=1);

namespace MMP\Compat;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MMP\Folder\FolderRepository;
use MMP\Gallery\GalleryRenderer;

/**
 * Beaver Builder Module — MMP Gallery (S42).
 *
 * Extends FLBuilderModule so users can insert and configure an MMP Gallery
 * directly inside the Beaver Builder drag-and-drop editor.
 *
 * Registration: FLBuilder::register_module() is called in the static
 * `register()` method, which is invoked on `init` from Plugin::registerServices()
 * — bails silently if Beaver Builder is not active.
 *
 * @package MMP\Compat
 * @since   1.0.0
 */
class BeaverBuilderGalleryModule extends \FLBuilderModule {

    // -------------------------------------------------------------------------
    // Registration (static entry point)
    // -------------------------------------------------------------------------

    /**
     * Register the module and its form config with Beaver Builder.
     * Called once on `init` after checking FLBuilder is available.
     */
    public static function register( FolderRepository $folderRepository ): void {
        \FLBuilder::register_module(
            self::class,
            [
                'source' => [
                    'title'    => __( 'Source', 'media-management-platform'),
                    'sections' => [
                        'folder_section' => [
                            'title'  => '',
                            'fields' => [
                                'folder_id' => [
                                    'type'    => 'select',
                                    'label'   => __( 'Folder', 'media-management-platform'),
                                    'default' => '0',
                                    'options' => self::buildFolderOptions( $folderRepository ),
                                    'help'    => __( 'Choose the MMP folder to display as a gallery.', 'media-management-platform'),
                                ],
                            ],
                        ],
                    ],
                ],
                'layout' => [
                    'title'    => __( 'Layout', 'media-management-platform'),
                    'sections' => [
                        'layout_section' => [
                            'title'  => '',
                            'fields' => [
                                'layout' => [
                                    'type'    => 'select',
                                    'label'   => __( 'Layout', 'media-management-platform'),
                                    'default' => 'grid',
                                    'options' => [
                                        'grid'     => __( 'Grid', 'media-management-platform'),
                                        'masonry'  => __( 'Masonry', 'media-management-platform'),
                                        'flex'     => __( 'Flex', 'media-management-platform'),
                                        'carousel' => __( 'Carousel', 'media-management-platform'),
                                    ],
                                ],
                                'columns' => [
                                    'type'    => 'unit',
                                    'label'   => __( 'Columns', 'media-management-platform'),
                                    'default' => '3',
                                    'slider'  => [
                                        'min'  => 1,
                                        'max'  => 8,
                                        'step' => 1,
                                    ],
                                    'units'   => [ '' ],
                                ],
                                'gap' => [
                                    'type'    => 'unit',
                                    'label'   => __( 'Gap (px)', 'media-management-platform'),
                                    'default' => '16',
                                    'slider'  => [
                                        'min'  => 0,
                                        'max'  => 64,
                                        'step' => 1,
                                    ],
                                    'units'   => [ '' ],
                                ],
                                'image_size' => [
                                    'type'    => 'select',
                                    'label'   => __( 'Image Size', 'media-management-platform'),
                                    'default' => 'medium',
                                    'options' => [
                                        'thumbnail' => __( 'Thumbnail', 'media-management-platform'),
                                        'medium'    => __( 'Medium', 'media-management-platform'),
                                        'large'     => __( 'Large', 'media-management-platform'),
                                        'full'      => __( 'Full', 'media-management-platform'),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'options' => [
                    'title'    => __( 'Options', 'media-management-platform'),
                    'sections' => [
                        'options_section' => [
                            'title'  => '',
                            'fields' => [
                                'lightbox' => [
                                    'type'    => 'select',
                                    'label'   => __( 'Lightbox', 'media-management-platform'),
                                    'default' => '1',
                                    'options' => [
                                        '1' => __( 'Enabled', 'media-management-platform'),
                                        '0' => __( 'Disabled', 'media-management-platform'),
                                    ],
                                ],
                                'caption' => [
                                    'type'    => 'select',
                                    'label'   => __( 'Show Caption', 'media-management-platform'),
                                    'default' => '0',
                                    'options' => [
                                        '1' => __( 'Yes', 'media-management-platform'),
                                        '0' => __( 'No', 'media-management-platform'),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function __construct() {
        parent::__construct( [
            'name'            => __( 'MMP Gallery', 'media-management-platform'),
            'description'     => __( 'Display images from a Media Management Platform folder.', 'media-management-platform'),
            'category'        => __( 'Media', 'media-management-platform'),
            'partial_refresh' => true,
        ] );
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    /**
     * Called by Beaver Builder to render the module on the frontend
     * and inside the live editor preview.
     */
    public function render(): void {
        $folderRepository = new FolderRepository();
        $renderer         = new GalleryRenderer( $folderRepository );

        echo $renderer->render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered HTML from trusted internal GalleryRenderer
            'folderId'  => absint( $this->settings->folder_id ?? 0 ),
            'layout'    => sanitize_key( (string) ( $this->settings->layout     ?? 'grid' ) ),
            'columns'   => (int) ( $this->settings->columns   ?? 3 ),
            'gap'       => (int) ( $this->settings->gap       ?? 16 ),
            'lightbox'  => (bool) ( $this->settings->lightbox ?? 1 ),
            'caption'   => (bool) ( $this->settings->caption  ?? 0 ),
            'imageSize' => sanitize_key( (string) ( $this->settings->image_size ?? 'medium' ) ),
        ] );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * @param  FolderRepository $repo
     * @return array<string, string>
     */
    private static function buildFolderOptions( FolderRepository $repo ): array {
        $options = [ '0' => __( '— Select folder —', 'media-management-platform') ];
        $tree    = $repo->getTree( 0 );
        self::flattenTree( $tree, $options, 0 );
        return $options;
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @param array<string, string>            $options
     * @param int                              $depth
     */
    private static function flattenTree( array $nodes, array &$options, int $depth ): void {
        foreach ( $nodes as $node ) {
            $id   = (string) ( $node['id']   ?? 0 );
            $name = (string) ( $node['name'] ?? '' );
            $options[ $id ] = str_repeat( '— ', $depth ) . $name;
            if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
                self::flattenTree( $node['children'], $options, $depth + 1 );
            }
        }
    }
}
