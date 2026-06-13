<?php

declare(strict_types=1);

namespace MMP\Taxonomy;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Registers and manages the `mmp_folder` hierarchical taxonomy.
 *
 * The taxonomy is attached to `attachment` by default.  Developers may extend
 * it to additional post types via the `mmp_supported_post_types` filter:
 *
 *   add_filter( 'mmp_supported_post_types', fn( $types ) => [ ...$types, 'post' ] );
 *
 * WP's built-in taxonomy UI is intentionally hidden — the plugin ships its own
 * React-based folder tree in the media library sidebar.
 *
 * @package MMP\Taxonomy
 * @since   1.0.0
 */
class FolderTaxonomy {

    /**
     * Taxonomy identifier used throughout the plugin.
     */
    public const TAXONOMY = 'mmp_folder';

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Hook taxonomy registration onto the `init` action.
     *
     * Called once from Plugin::registerServices().
     */
    public function register(): void {
        add_action( 'init', [ $this, 'registerTaxonomy' ] );
    }

    /**
     * Register the `mmp_folder` taxonomy with WordPress.
     *
     * Fires on the `init` action. Attaches to every post type returned by the
     * `mmp_supported_post_types` filter (defaults to `attachment`).
     *
     * @internal Hooked via register(); do not call directly.
     */
    public function registerTaxonomy(): void {
        /**
         * Filters the list of post types that receive the `mmp_folder` taxonomy.
         *
         * @since 1.0.0
         *
         * @param string[] $post_types Post type slugs. Default: ['attachment'].
         */
        $post_types = (array) apply_filters(
            'mmp_supported_post_types',
            [ 'attachment' ]
        );

        register_taxonomy(
            self::TAXONOMY,
            $post_types,
            $this->taxonomyArgs()
        );
    }

    /**
     * Return the taxonomy name.
     *
     * Convenience wrapper around the TAXONOMY constant for callers that hold
     * an instance rather than referencing the class directly.
     */
    public function getTaxonomyName(): string {
        return self::TAXONOMY;
    }

    /**
     * Check whether the taxonomy has been registered with WordPress.
     */
    public function isRegistered(): bool {
        return taxonomy_exists( self::TAXONOMY );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build the full argument array passed to register_taxonomy().
     *
     * @return array<string, mixed>
     */
    private function taxonomyArgs(): array {
        return [
            // ------------------------------------------------------------------
            // Hierarchy
            // ------------------------------------------------------------------
            'hierarchical'          => true,

            // ------------------------------------------------------------------
            // Labels
            // ------------------------------------------------------------------
            'labels'                => $this->taxonomyLabels(),

            // ------------------------------------------------------------------
            // UI visibility — hidden everywhere; we use our own React UI.
            // ------------------------------------------------------------------
            'show_ui'               => false,
            'show_in_nav_menus'     => false,
            'show_tagcloud'         => false,
            'show_admin_column'     => false,   // custom column added separately
            'meta_box_cb'           => false,   // disable the default meta box

            // ------------------------------------------------------------------
            // REST API — enabled so media queries can filter by folder.
            // ------------------------------------------------------------------
            'show_in_rest'          => true,
            'rest_base'             => 'mmp-folders',
            'rest_controller_class' => 'WP_REST_Terms_Controller',

            // ------------------------------------------------------------------
            // Query var — allows ?mmp_folder=slug filtering in WP_Query.
            // ------------------------------------------------------------------
            'query_var'             => self::TAXONOMY,

            // ------------------------------------------------------------------
            // Rewrite — disabled; virtual folders have no frontend URLs.
            // ------------------------------------------------------------------
            'rewrite'               => false,

            // ------------------------------------------------------------------
            // Capabilities — all folder management requires custom cap.
            // ------------------------------------------------------------------
            'capabilities'          => [
                'manage_terms' => 'manage_mmp_folders',
                'edit_terms'   => 'manage_mmp_folders',
                'delete_terms' => 'manage_mmp_folders',
                'assign_terms' => 'upload_files',
            ],

            // ------------------------------------------------------------------
            // Miscellaneous
            // ------------------------------------------------------------------
            'public'                => false,
            'publicly_queryable'    => false,
            'show_in_quick_edit'    => false,
            'sort'                  => false,
        ];
    }

    /**
     * Build the full label set for the taxonomy.
     *
     * All strings use the `mmp` text domain so they are translation-ready.
     *
     * @return array<string, string>
     */
    private function taxonomyLabels(): array {
        return [
            'name'                       => _x( 'Folders', 'taxonomy general name', 'media-management-platform'),
            'singular_name'              => _x( 'Folder', 'taxonomy singular name', 'media-management-platform'),
            'search_items'               => __( 'Search Folders', 'media-management-platform'),
            'popular_items'              => __( 'Popular Folders', 'media-management-platform'),
            'all_items'                  => __( 'All Folders', 'media-management-platform'),
            'parent_item'                => __( 'Parent Folder', 'media-management-platform'),
            'parent_item_colon'          => __( 'Parent Folder:', 'media-management-platform'),
            'edit_item'                  => __( 'Edit Folder', 'media-management-platform'),
            'view_item'                  => __( 'View Folder', 'media-management-platform'),
            'update_item'                => __( 'Update Folder', 'media-management-platform'),
            'add_new_item'               => __( 'Add New Folder', 'media-management-platform'),
            'new_item_name'              => __( 'New Folder Name', 'media-management-platform'),
            'separate_items_with_commas' => __( 'Separate folders with commas', 'media-management-platform'),
            'add_or_remove_items'        => __( 'Add or remove folders', 'media-management-platform'),
            'choose_from_most_used'      => __( 'Choose from the most used folders', 'media-management-platform'),
            'not_found'                  => __( 'No folders found.', 'media-management-platform'),
            'no_terms'                   => __( 'No folders', 'media-management-platform'),
            'filter_by_item'             => __( 'Filter by folder', 'media-management-platform'),
            'items_list_navigation'      => __( 'Folders list navigation', 'media-management-platform'),
            'items_list'                 => __( 'Folders list', 'media-management-platform'),
            'most_used'                  => _x( 'Most Used', 'folder taxonomy', 'media-management-platform'),
            'back_to_items'              => __( '&larr; Go to Folders', 'media-management-platform'),
            'item_link'                  => _x( 'Folder Link', 'navigation link block title', 'media-management-platform'),
            'item_link_description'      => _x( 'A link to a folder.', 'navigation link block description', 'media-management-platform'),
            'menu_name'                  => _x( 'Folders', 'admin menu', 'media-management-platform'),
        ];
    }
}
