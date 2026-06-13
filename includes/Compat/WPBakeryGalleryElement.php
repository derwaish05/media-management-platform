<?php

declare(strict_types=1);

namespace MMP\Compat;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MMP\Folder\FolderRepository;
use MMP\Gallery\GalleryRenderer;

/**
 * WPBakery Page Builder Element — MMP Gallery (S42).
 *
 * Registers an "MMP Gallery" element via `vc_map()` and a companion
 * shortcode `[mmp_gallery_vc]` that WPBakery renders for both the
 * frontend and the backend editor.
 *
 * Registration: `register()` is called on `vc_before_init` from
 * Plugin::registerServices() — bails silently if WPBakery is not active.
 *
 * @package MMP\Compat
 * @since   1.0.0
 */
class WPBakeryGalleryElement {

    public function __construct(
        private readonly FolderRepository $folderRepository,
    ) {}

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    public function register(): void {
        if ( ! function_exists( 'vc_map' ) ) {
            return;
        }

        vc_map( [
            'name'        => __( 'MMP Gallery', 'media-management-platform'),
            'base'        => 'mmp_gallery_vc',
            'description' => __( 'Display images from a Media Management Platform folder.', 'media-management-platform'),
            'category'    => __( 'Media', 'media-management-platform'),
            'icon'        => 'vc_general vc_el_icon vc_icon-vc-gallery',
            'params'      => $this->buildParams(),
        ] );

        add_shortcode( 'mmp_gallery_vc', [ $this, 'renderShortcode' ] );
    }

    // -------------------------------------------------------------------------
    // Shortcode callback
    // -------------------------------------------------------------------------

    /**
     * WPBakery renders the element by calling this shortcode.
     *
     * @param  array<string, mixed>|string $attrs
     * @return string  Safe HTML.
     */
    public function renderShortcode( array|string $attrs ): string {
        $attrs = shortcode_atts(
            [
                'folder_id'  => 0,
                'layout'     => 'grid',
                'columns'    => 3,
                'gap'        => 16,
                'image_size' => 'medium',
                'lightbox'   => 'yes',
                'caption'    => 'no',
            ],
            is_array( $attrs ) ? $attrs : [],
            'mmp_gallery_vc'
        );

        $renderer = new GalleryRenderer( $this->folderRepository );

        return $renderer->render( [
            'folderId'  => absint( $attrs['folder_id'] ),
            'layout'    => sanitize_key( (string) $attrs['layout'] ),
            'columns'   => (int) $attrs['columns'],
            'gap'       => (int) $attrs['gap'],
            'lightbox'  => ( $attrs['lightbox'] === 'yes' ),
            'caption'   => ( $attrs['caption']  === 'yes' ),
            'imageSize' => sanitize_key( (string) $attrs['image_size'] ),
        ] );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build the `vc_map` params array.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildParams(): array {
        return [
            [
                'type'        => 'dropdown',
                'heading'     => __( 'Folder', 'media-management-platform'),
                'param_name'  => 'folder_id',
                'value'       => $this->getFolderOptions(),
                'description' => __( 'Select the MMP folder to display.', 'media-management-platform'),
                'group'       => __( 'Source', 'media-management-platform'),
            ],
            [
                'type'       => 'dropdown',
                'heading'    => __( 'Layout', 'media-management-platform'),
                'param_name' => 'layout',
                'value'      => [
                    __( 'Grid', 'media-management-platform')     => 'grid',
                    __( 'Masonry', 'media-management-platform')  => 'masonry',
                    __( 'Flex', 'media-management-platform')     => 'flex',
                    __( 'Carousel', 'media-management-platform') => 'carousel',
                ],
                'std'        => 'grid',
                'group'      => __( 'Layout', 'media-management-platform'),
            ],
            [
                'type'       => 'number',
                'heading'    => __( 'Columns', 'media-management-platform'),
                'param_name' => 'columns',
                'value'      => 3,
                'min'        => 1,
                'max'        => 8,
                'group'      => __( 'Layout', 'media-management-platform'),
            ],
            [
                'type'       => 'number',
                'heading'    => __( 'Gap (px)', 'media-management-platform'),
                'param_name' => 'gap',
                'value'      => 16,
                'min'        => 0,
                'max'        => 64,
                'group'      => __( 'Layout', 'media-management-platform'),
            ],
            [
                'type'       => 'dropdown',
                'heading'    => __( 'Image Size', 'media-management-platform'),
                'param_name' => 'image_size',
                'value'      => [
                    __( 'Thumbnail', 'media-management-platform') => 'thumbnail',
                    __( 'Medium', 'media-management-platform')    => 'medium',
                    __( 'Large', 'media-management-platform')     => 'large',
                    __( 'Full', 'media-management-platform')      => 'full',
                ],
                'std'        => 'medium',
                'group'      => __( 'Layout', 'media-management-platform'),
            ],
            [
                'type'       => 'checkbox',
                'heading'    => __( 'Lightbox', 'media-management-platform'),
                'param_name' => 'lightbox',
                'value'      => [ __( 'Enable lightbox', 'media-management-platform') => 'yes' ],
                'std'        => 'yes',
                'group'      => __( 'Options', 'media-management-platform'),
            ],
            [
                'type'       => 'checkbox',
                'heading'    => __( 'Show Caption', 'media-management-platform'),
                'param_name' => 'caption',
                'value'      => [ __( 'Show image captions', 'media-management-platform') => 'yes' ],
                'std'        => '',
                'group'      => __( 'Options', 'media-management-platform'),
            ],
        ];
    }

    /**
     * WPBakery dropdown `value` key uses `[Label => value]` format.
     *
     * @return array<string, string>
     */
    private function getFolderOptions(): array {
        $options = [ __( '— Select folder —', 'media-management-platform') => '0' ];
        $tree    = $this->folderRepository->getTree( 0 );
        $this->flattenToOptions( $tree, $options, 0 );
        return $options;
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @param array<string, string>            $options
     * @param int                              $depth
     */
    private function flattenToOptions( array $nodes, array &$options, int $depth ): void {
        foreach ( $nodes as $node ) {
            $id   = (string) ( $node['id']   ?? 0 );
            $name = (string) ( $node['name'] ?? '' );
            $label = str_repeat( '— ', $depth ) . $name;
            $options[ $label ] = $id;
            if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
                $this->flattenToOptions( $node['children'], $options, $depth + 1 );
            }
        }
    }
}
