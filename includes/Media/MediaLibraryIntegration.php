<?php

declare(strict_types=1);

namespace MMP\Media;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MMP\Folder\FolderRepository;
use MMP\Folder\FolderService;
use MMP\Taxonomy\FolderTaxonomy;

/**
 * Hooks into the WordPress admin media library to:
 *
 *  1. Filter the media grid/list view when a folder is selected in the sidebar.
 *  2. Add a "Folder" column to the WP Media List view.
 *  3. Inject mmp_folder_id / mmp_folder_name into the attachment JS data
 *     (media modal compatibility).
 *  4. Add a "Move to Folder" dropdown to the attachment edit fields in the
 *     media modal.
 *  5. Enqueue the built React/CSS assets on the correct admin screens.
 *  6. Inject the React mount points and MmpConfig into upload.php.
 *
 * @package MMP\Media
 * @since   1.0.0
 */
class MediaLibraryIntegration {

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function __construct(
        private readonly MediaRepository $mediaRepository,
        private readonly FolderRepository $folderRepository,
        private readonly FolderService $folderService,
        private readonly ?UsageTracker $usageTracker = null,
    ) {}

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    /**
     * Register all hooks. Called once from Plugin::registerServices().
     */
    public function register(): void {
        // Filter media query when folder param present in the request (page-load).
        add_action('pre_get_posts', [$this, 'filterByFolder']);

        // Filter media query for WP backbone AJAX (grid view, no page reload).
        add_filter('ajax_query_attachments_args', [$this, 'filterAjaxByFolder']);
        add_filter('ajax_query_attachments_args', [$this, 'filterAjaxBySortSize']);

        // Store filesize meta on upload so we can sort by it via meta_value_num.
        add_filter('wp_generate_attachment_metadata', [$this, 'storeFilesizeMeta'], 10, 2);
        add_action('add_attachment', [$this, 'storeFilesizeMetaById']);

        // Add "Folder" column to the WP Media List view.
        add_filter('manage_upload_columns', [$this, 'addFolderColumn']);
        add_action('manage_media_custom_column', [$this, 'renderFolderColumn'], 10, 2);

        // Inject folder data into the media modal attachment JS object.
        add_filter('wp_prepare_attachment_for_js', [$this, 'addFolderDataToJs'], 10, 3);

        // Add folder selector to the attachment edit form inside the media modal.
        add_filter('attachment_fields_to_edit', [$this, 'addFolderField'], 10, 2);
        add_filter('attachment_fields_to_save', [$this, 'saveFolderField'], 10, 2);

        // Enqueue admin assets on the correct screens only.
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);

        // Inject React mount points into the footer of upload.php.
        add_action('admin_footer-upload.php', [$this, 'injectSidebarMount']);

        // Inject the WP-selection → React bridge + "Select All" toolbar.
        add_action('admin_footer-upload.php', [$this, 'injectBulkSelectionBridge']);

        // Usage tracker UI hooks (S33).
        if (null !== $this->usageTracker) {
            // Add "Used In" field to attachment details in the media modal.
            add_filter('attachment_fields_to_edit', [$this, 'addUsedInField'], 20, 2);

            // Add mmp_usage_count + mmp_used_in_published to JS attachment data.
            add_filter('wp_prepare_attachment_for_js', [$this, 'addUsageDataToJs'], 20, 3);

            // Add warning column to media list view.
            add_filter('manage_upload_columns', [$this, 'addUsageColumn']);
            add_action('manage_media_custom_column', [$this, 'renderUsageColumn'], 10, 2);

            // Filter for "Unused Media" query flag.
            add_filter('ajax_query_attachments_args', [$this, 'filterAjaxUnused']);
        }
    }

    // -------------------------------------------------------------------------
    // Hook Callbacks — Media Query Filter
    // -------------------------------------------------------------------------

    /**
     * Modifies the main WP_Query on the media library screen to filter
     * attachments by the mmp_folder_id URL parameter.
     *
     * Reads ?mmp_folder_id from $_GET (set by our React sidebar).
     *   mmp_folder_id absent or -1 → no filter (show all)
     *   mmp_folder_id = 0           → Uncategorized (NOT EXISTS tax_query)
     *   mmp_folder_id > 0           → specific folder term
     *
     * @param \WP_Query $query  The current WP_Query instance.
     */
    public function filterByFolder(\WP_Query $query): void {
        if (!$query->is_main_query()) {
            return;
        }

        if ($query->get('post_type') !== 'attachment') {
            return;
        }

        if (!is_admin()) {
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $folderId  = isset($_GET['mmp_folder_id']) ? (int) $_GET['mmp_folder_id'] : -1;
        $mmpSort   = isset($_GET['mmp_sort'])   ? sanitize_key($_GET['mmp_sort'])                      : '';
        $mmpOrder  = isset($_GET['mmp_order'])  ? strtoupper(sanitize_key($_GET['mmp_order']))          : '';
        $mmpSearch = isset($_GET['mmp_search']) ? sanitize_text_field(wp_unslash($_GET['mmp_search'])) : '';
        // phpcs:enable

        // ---- Sort / order / search — applied regardless of folder filter ----

        $orderbyMap = [
            'name'     => 'title',
            'date'     => 'date',
            'modified' => 'modified',
            'author'   => 'author',
            // File size is not a native WP orderby field; falls back to date
            // until a dedicated _mmp_filesize meta index is introduced.
            'size'     => 'date',
        ];

        if ($mmpSort !== '' && isset($orderbyMap[$mmpSort])) {
            $query->set('orderby', $orderbyMap[$mmpSort]);
        }

        if (in_array($mmpOrder, ['ASC', 'DESC'], true)) {
            $query->set('order', $mmpOrder);
        }

        if ($mmpSearch !== '') {
            $query->set('s', $mmpSearch);
        }

        // ---- Folder filter --------------------------------------------------

        if ($folderId === -1) {
            // No folder selected — show everything (sort/search still applied above).
            return;
        }

        if ($folderId === 0) {
            // Uncategorized: attachments not assigned to any mmp_folder term.
            $query->set('tax_query', [
                [
                    'taxonomy' => FolderTaxonomy::TAXONOMY,
                    'field'    => 'term_id',
                    'operator' => 'NOT EXISTS',
                ],
            ]);
            return;
        }

        // Specific folder.
        $query->set('tax_query', [
            [
                'taxonomy' => FolderTaxonomy::TAXONOMY,
                'field'    => 'term_id',
                'terms'    => [$folderId],
                'operator' => 'IN',
            ],
        ]);
    }

    /**
     * Filters WP backbone AJAX attachment queries by folder.
     *
     * Called via the `ajax_query_attachments_args` filter when the React
     * sidebar dispatches an `mmp:folder-selected` event and the bridge JS
     * sets `mmp_folder_id` on the backbone collection props.
     *
     * @param  array<string, mixed> $query  WP_Query args array.
     * @return array<string, mixed>
     */
    public function filterAjaxByFolder(array $query): array {
        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $folderId = isset( $_POST['query']['mmp_folder_id'] )
            ? sanitize_text_field( wp_unslash( (string) $_POST['query']['mmp_folder_id'] ) )
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if ('' === $folderId) {
            return $query;
        }

        $folderId = (int) $folderId;

        if ($folderId === 0) {
            // Uncategorized: attachments not assigned to any folder term.
            $query['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                [
                    'taxonomy' => FolderTaxonomy::TAXONOMY,
                    'field'    => 'term_id',
                    'operator' => 'NOT EXISTS',
                ],
            ];
        } else {
            // Specific folder.
            $query['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                [
                    'taxonomy' => FolderTaxonomy::TAXONOMY,
                    'field'    => 'term_id',
                    'terms'    => [$folderId],
                    'operator' => 'IN',
                ],
            ];
        }

        return $query;
    }

    /**
     * Converts `orderby=filesize` in the backbone AJAX query to a sortable
     * meta_value_num query on the `_mmp_filesize` post meta key.
     *
     * Called via the `ajax_query_attachments_args` filter.
     *
     * @param  array<string, mixed> $query  WP_Query args array from the AJAX handler.
     * @return array<string, mixed>
     */
    public function filterAjaxBySortSize(array $query): array {
        if (( $query['orderby'] ?? '' ) !== 'filesize') {
            return $query;
        }

        $query['orderby']  = 'meta_value_num';
        $query['meta_key'] = '_mmp_filesize'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key

        return $query;
    }

    /**
     * Stores the filesize of an attachment as `_mmp_filesize` post meta so
     * it can be used for sorting via `meta_value_num`.
     *
     * Hooked to `wp_generate_attachment_metadata` (fires for images after upload
     * and when metadata is regenerated). Returns metadata unchanged.
     *
     * @param  array<string, mixed> $metadata      Attachment metadata array.
     * @param  int                  $attachmentId  Attachment post ID.
     * @return array<string, mixed>
     */
    public function storeFilesizeMeta(array $metadata, int $attachmentId): array {
        $this->storeFilesizeMetaById($attachmentId);
        return $metadata;
    }

    /**
     * Stores the filesize meta for a given attachment ID.
     * Called directly from `add_attachment` (covers non-image uploads that
     * don't trigger `wp_generate_attachment_metadata`).
     *
     * @param int $attachmentId  Attachment post ID.
     */
    public function storeFilesizeMetaById(int $attachmentId): void {
        $filePath = get_attached_file($attachmentId);
        if ($filePath && file_exists($filePath)) {
            update_post_meta($attachmentId, '_mmp_filesize', (int) filesize($filePath));
        }
    }

    // -------------------------------------------------------------------------
    // Hook Callbacks — List View Column
    // -------------------------------------------------------------------------

    /**
     * Registers the "Folder" column in the WP Media Library list view.
     *
     * @param  array<string, string> $columns  Existing column definitions.
     * @return array<string, string>
     */
    public function addFolderColumn(array $columns): array {
        $columns['mmp_folder'] = __('Folder', 'media-management-platform');

        return $columns;
    }

    /**
     * Renders the folder name (or an em-dash for Uncategorized) in the
     * custom Folder column of the WP Media Library list view.
     *
     * @param string $columnName  Current column machine name.
     * @param int    $postId      Current attachment post ID.
     */
    public function renderFolderColumn(string $columnName, int $postId): void {
        if ($columnName !== 'mmp_folder') {
            return;
        }

        $folderId = $this->mediaRepository->getFileFolder($postId);

        if ($folderId === 0) {
            echo '<span class="mmp-uncategorized">&mdash;</span>';
            return;
        }

        $folder = $this->folderRepository->getById($folderId);
        $name   = $folder ? esc_html($folder['name']) : esc_html((string) $folderId);

        echo '<span class="mmp-folder-label">' . $name . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $name is esc_html() escaped on line above
    }

    // -------------------------------------------------------------------------
    // Hook Callbacks — Media Modal JS Data
    // -------------------------------------------------------------------------

    /**
     * Adds mmp_folder_id and mmp_folder_name to the JS attachment data object
     * that WordPress passes to the media modal (attachment.js).
     *
     * This allows the React sidebar and the media modal to read the current
     * folder assignment without an additional API call.
     *
     * @param  array<string, mixed> $response    Attachment data array for JS.
     * @param  \WP_Post             $attachment  Attachment post object.
     * @param  array<string, mixed> $meta        Attachment metadata.
     * @return array<string, mixed>
     */
    public function addFolderDataToJs(array $response, \WP_Post $attachment, array $meta): array {
        $folderId = $this->mediaRepository->getFileFolder($attachment->ID);

        $folderName = '';
        if ($folderId > 0) {
            $folder = $this->folderRepository->getById($folderId);
            if (null !== $folder) {
                $folderName = (string) $folder['name'];
            }
        }

        $response['mmp_folder_id']   = $folderId;
        $response['mmp_folder_name'] = $folderName;

        return $response;
    }

    // -------------------------------------------------------------------------
    // Hook Callbacks — Attachment Edit Fields (Media Modal)
    // -------------------------------------------------------------------------

    /**
     * Adds a "Folder" dropdown field to the attachment edit form fields shown
     * inside the media modal.
     *
     * Renders a <select> listing all folders plus an "Uncategorized" option,
     * with the current folder pre-selected.
     *
     * @param  array<string, mixed> $formFields  Existing form fields.
     * @param  \WP_Post             $post        Attachment post object.
     * @return array<string, mixed>
     */
    public function addFolderField(array $formFields, \WP_Post $post): array {
        $currentFolderId = $this->mediaRepository->getFileFolder($post->ID);

        $terms = get_terms([
            'taxonomy'   => FolderTaxonomy::TAXONOMY,
            'hide_empty' => false,
            'number'     => 0,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);

        $selectName = 'attachments[' . $post->ID . '][mmp_folder_id]';

        $html  = '<select name="' . esc_attr($selectName) . '" id="mmp-folder-' . (int) $post->ID . '">';
        $html .= '<option value="0"' . selected($currentFolderId, 0, false) . '>'
               . esc_html__('Uncategorized', 'media-management-platform')
               . '</option>';

        if (!is_wp_error($terms) && is_array($terms)) {
            foreach ($terms as $term) {
                if (!($term instanceof \WP_Term)) {
                    continue;
                }

                $html .= '<option value="' . (int) $term->term_id . '"'
                       . selected($currentFolderId, (int) $term->term_id, false)
                       . '>'
                       . esc_html($term->name)
                       . '</option>';
            }
        }

        $html .= '</select>';

        $formFields['mmp_folder_id'] = [
            'label'         => __('Folder', 'media-management-platform'),
            'input'         => 'html',
            'html'          => $html,
            'show_in_edit'  => true,
            'show_in_modal' => true,
        ];

        return $formFields;
    }

    /**
     * Saves the folder selection when an attachment is updated via the
     * attachment edit fields in the media modal.
     *
     * @param  array<string, mixed> $post        Attachment post data (returned unchanged).
     * @param  array<string, mixed> $attachment  Submitted form field values.
     * @return array<string, mixed>  Unmodified $post array.
     */
    public function saveFolderField(array $post, array $attachment): array {
        $folderId = absint($attachment['mmp_folder_id'] ?? 0);

        $this->mediaRepository->assignToFolder((int) $post['ID'], $folderId);

        return $post;
    }

    // -------------------------------------------------------------------------
    // Hook Callbacks — Usage Tracker UI (S33)
    // -------------------------------------------------------------------------

    /**
     * Adds a read-only "Used In" field to the attachment details panel inside
     * the WordPress media modal.
     *
     * @param  array<string, mixed> $formFields
     * @param  \WP_Post             $post
     * @return array<string, mixed>
     */
    public function addUsedInField(array $formFields, \WP_Post $post): array {
        if (null === $this->usageTracker) {
            return $formFields;
        }

        $usage = $this->usageTracker->getUsageForAttachment($post->ID);
        $count = count($usage);

        if ($count === 0) {
            $html = '<span style="color:#888">' . esc_html__('Not used anywhere', 'media-management-platform') . '</span>';
        } else {
            $items = '';
            foreach (array_slice($usage, 0, 10) as $item) {
                $status = 'publish' === $item['post_status']
                    ? ' <span style="color:#d63638">●</span>'
                    : '';
                $items .= '<li>'
                    . '<a href="' . esc_url($item['permalink']) . '" target="_blank">'
                    . esc_html($item['title'])
                    . '</a>'
                    . ' <em style="color:#888">(' . esc_html($item['context']) . ')</em>'
                    . $status
                    . '</li>';
            }

            $more = $count > 10 ? '<li><em>' . sprintf(
                /* translators: %d: number of additional usage locations */
                esc_html__('…and %d more', 'media-management-platform'),
                $count - 10
            ) . '</em></li>' : '';

            $html = '<ul style="margin:0;padding-left:1.2em">' . $items . $more . '</ul>';
        }

        $formFields['mmp_used_in'] = [
            'label'         => __('Used In', 'media-management-platform'),
            'input'         => 'html',
            'html'          => $html,
            'show_in_edit'  => false,
            'show_in_modal' => true,
        ];

        return $formFields;
    }

    /**
     * Adds usage count and published-usage flag to the attachment JS data
     * object so the media grid can show a warning icon.
     *
     * @param  array<string, mixed> $response
     * @param  \WP_Post             $attachment
     * @param  array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public function addUsageDataToJs(array $response, \WP_Post $attachment, array $meta): array {
        if (null === $this->usageTracker) {
            return $response;
        }

        $response['mmp_usage_count']         = $this->usageTracker->countUsage($attachment->ID);
        $response['mmp_used_in_published']   = $this->usageTracker->isUsedInPublished($attachment->ID);

        return $response;
    }

    /**
     * Adds a "Usage" column header to the WP Media List view.
     *
     * @param  array<string, string> $columns
     * @return array<string, string>
     */
    public function addUsageColumn(array $columns): array {
        $columns['mmp_usage'] = __('Usage', 'media-management-platform');
        return $columns;
    }

    /**
     * Renders the Usage column — shows a warning icon when the file is used
     * in published content, or the usage count otherwise.
     *
     * @param string $columnName
     * @param int    $postId
     */
    public function renderUsageColumn(string $columnName, int $postId): void {
        if ($columnName !== 'mmp_usage' || null === $this->usageTracker) {
            return;
        }

        $count     = $this->usageTracker->countUsage($postId);
        $published = $this->usageTracker->isUsedInPublished($postId);

        if ($count === 0) {
            echo '<span style="color:#9ca3af" title="' . esc_attr__('Not used anywhere', 'media-management-platform') . '">—</span>';
            return;
        }

        $icon = $published
            ? '<span title="' . esc_attr__('Used in published content — deleting may break pages', 'media-management-platform') . '" style="color:#d63638;font-size:16px">⚠</span> '
            : '';

        echo $icon . '<span title="' . esc_attr__('Usage count', 'media-management-platform') . '">' . (int) $count . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $icon contains trusted hardcoded HTML with escaped attributes
    }

    /**
     * Filters the backbone AJAX attachment query to return only unused media
     * when the `mmp_unused` parameter is set to 1.
     *
     * @param  array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function filterAjaxUnused(array $query): array {
        if (empty($query['mmp_unused'])) {
            return $query;
        }

        global $wpdb;

        // Subquery: attachment IDs that appear in wp_mmp_usage.
        $usedIds = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT DISTINCT attachment_id FROM {$wpdb->prefix}mmp_usage"
        );

        if (empty($usedIds)) {
            return $query; // No usage data yet — don't filter.
        }

        $query['post__not_in'] = array_merge(  // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
            (array) ($query['post__not_in'] ?? []),
            array_map('intval', $usedIds)
        );

        return $query;
    }

    // -------------------------------------------------------------------------
    // Hook Callbacks — Asset Enqueueing
    // -------------------------------------------------------------------------

    /**
     * Enqueues the compiled React JS bundle and CSS stylesheet on the admin
     * screens where the media library or media modal may be active.
     *
     * Screens: upload.php, post.php, post-new.php, async-upload.php
     *
     * @param string $hook  Current admin page hook suffix.
     */
    public function enqueueAssets(string $hook): void {
        $allowedHooks = ['upload.php', 'post.php', 'post-new.php', 'async-upload.php'];

        if (!in_array($hook, $allowedHooks, true)) {
            return;
        }

        wp_enqueue_style(
            'mmp-admin',
            MMP_URL . 'admin/assets/dist/mmp-admin.css',
            [],
            MMP_VERSION
        );

        wp_enqueue_script(
            'mmp-admin',
            MMP_URL . 'admin/assets/dist/mmp-admin.js',
            [],
            MMP_VERSION,
            true
        );
    }

    // -------------------------------------------------------------------------
    // Hook Callbacks — Sidebar Mount Point
    // -------------------------------------------------------------------------

    /**
     * Outputs the React mount points and the MmpConfig global into the footer
     * of upload.php so that the React application can boot.
     *
     * Outputs:
     *   <div id="mmp-root">       — primary React mount point
     *   <div id="mmp-sidebar-portal"> — portal target for the sidebar overlay
     *   window.MmpConfig          — bootstrap configuration object
     */
    public function injectSidebarMount(): void {
        global $wpdb;

        $userId   = get_current_user_id();
        $settings = (array) get_option('mmp_settings', []);

        $folderMode = isset($settings['folder_mode']) ? (string) $settings['folder_mode'] : 'global';

        // Flush all mmp_tree_* transients on every media library page load so
        // the initialTree is always built from a live DB query. This prevents
        // stale transients from causing the "No folders yet" bug.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_mmp_tree_%'
                OR option_name LIKE '_transient_timeout_mmp_tree_%'"
        );

        // Use the folder mode to decide which tree to load.
        $treeUserId = ('per_user' === $folderMode) ? $userId : 0;
        $initialTree = $this->folderService->getTree($treeUserId);

        $userPrefs = $this->getUserPrefs($userId);

        $config = [
            'restUrl'     => rest_url('mmp/v1'),
            'nonce'       => wp_create_nonce('wp_rest'),
            'userId'      => $userId,
            'isAdmin'     => current_user_can('manage_options'),
            'folderMode'  => $folderMode,
            'initialTree' => $initialTree,
            'userPrefs'   => $userPrefs,
            'licenceTier' => 'pro',
        ];

        echo '<div id="mmp-root" style="display:none;"></div>' . "\n";
        // Sidebar portal: hidden until bridge JS repositions it inside .media-frame-content.
        echo '<div id="mmp-sidebar-portal" style="display:none;"></div>' . "\n";
        // Portal targets for BreadcrumbBar and MediaToolbar.
        echo '<div id="mmp-breadcrumb-root" style="display:none;"></div>' . "\n";
        echo '<div id="mmp-toolbar-root" style="display:none;"></div>' . "\n";
        echo '<script>window.MmpConfig = ' . wp_json_encode( $config ) . ';</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode produces safe JSON
        // Temporary diagnostic — open browser DevTools > Console to see this.
        echo '<script>console.log("[MMP debug] folder_mode=" + ' . wp_json_encode( $folderMode ) . ' + ", initialTree count=" + window.MmpConfig.initialTree.length + ", userId=" + window.MmpConfig.userId);</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode is safe
        // Inline styles: ensure the sidebar portal and its flex wrapper fill the
        // full height of .media-frame-content once the bridge script makes it flex.
        echo '<style>
#mmp-sidebar-portal { height: 100%; overflow: hidden; flex-shrink: 0; }
#mmp-media-content-inner { flex: 1; min-width: 0; overflow: hidden; display: flex; flex-direction: column; }
#mmp-media-content-inner .wp-filter { flex-shrink: 0; }
#mmp-media-content-inner .attachments-browser { flex: 1; min-height: 0; overflow-y: auto; }
</style>' . "\n";
    }

    /**
     * Injects the JavaScript bridge that:
     *  1. Watches the WP media library DOM for .attachment.selected class changes
     *     (MutationObserver) and dispatches `mmp:selection-change` custom events
     *     with the array of selected attachment IDs so the React app can sync to
     *     selectionStore.
     *  2. Injects a "Select All in Folder" button into the WP media toolbar.
     *
     * The bridge uses a MutationObserver on `.attachments` (WP's grid container)
     * so it works with WP's backbone-rendered attachment items without monkey-
     * patching the backbone models.
     */
    public function injectBulkSelectionBridge(): void {
        ?>
<script>
(function () {
    'use strict';

    // -------------------------------------------------------------------------
    // 1. MutationObserver bridge — WP selection → mmp:selection-change event
    // -------------------------------------------------------------------------

    var observer = null;

    function getSelectedIds() {
        var els = document.querySelectorAll('.attachment.selected');
        var ids = [];
        els.forEach(function (el) {
            var id = parseInt(el.getAttribute('data-id') || '0', 10);
            if (id > 0) ids.push(id);
        });
        return ids;
    }

    function dispatchSelectionChange() {
        var ids = getSelectedIds();
        window.dispatchEvent(new CustomEvent('mmp:selection-change', { detail: { ids: ids } }));
    }

    function attachObserver() {
        var grid = document.querySelector('.attachments');
        if (!grid || observer) return;

        observer = new MutationObserver(function (mutations) {
            var relevant = mutations.some(function (m) {
                return m.type === 'attributes' && m.attributeName === 'class';
            });
            if (relevant) {
                dispatchSelectionChange();
            }
        });

        observer.observe(grid, {
            subtree: true,
            attributes: true,
            attributeFilter: ['class'],
        });
    }

    // -------------------------------------------------------------------------
    // 3. Position the folder sidebar inside the WP media frame
    // -------------------------------------------------------------------------

    /**
     * WP's upload.php (grid mode) renders:
     *   .media-frame-content
     *     .wp-filter          (search/filter bar)
     *     .attachments-browser (the grid)
     *
     * We turn .media-frame-content into a flex row, inject #mmp-sidebar-portal
     * as the first child, then wrap the WP elements (.wp-filter +
     * .attachments-browser) in a flex-1 column container so the sidebar sits
     * to the left of the grid.
     */
    function positionSidebarPortal() {
        var sidebarPortal = document.getElementById('mmp-sidebar-portal');
        if (!sidebarPortal) return;
        if (sidebarPortal.getAttribute('data-mmp-placed') === '1') return;

        // In grid mode, .media-frame-content is the flex parent.
        var frame = document.querySelector('.media-frame-content');
        if (!frame) return;

        // Bail if no WP content has rendered yet.
        if (!document.querySelector('.wp-filter') && !document.querySelector('.attachments-browser')) return;

        // --- Turn the frame into a horizontal flex container ---
        frame.style.cssText += ';display:flex!important;flex-direction:row;overflow:hidden;';

        // --- Insert sidebar as first child ---
        frame.insertBefore(sidebarPortal, frame.firstChild);
        sidebarPortal.style.cssText = 'flex-shrink:0;height:100%;overflow:hidden;position:relative;';

        // --- Wrap WP's filter + grid in a flex-1 column container ---
        if (!document.getElementById('mmp-media-content-inner')) {
            var inner = document.createElement('div');
            inner.id = 'mmp-media-content-inner';
            inner.style.cssText = 'flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0;';

            // Move every child except the sidebar portal into the inner wrapper.
            var children = Array.prototype.slice.call(frame.children);
            children.forEach(function (child) {
                if (child !== sidebarPortal) {
                    inner.appendChild(child);
                }
            });
            frame.appendChild(inner);
        }

        sidebarPortal.setAttribute('data-mmp-placed', '1');
    }

    // -------------------------------------------------------------------------
    // 4. Reposition BreadcrumbBar + MediaToolbar portal roots
    // -------------------------------------------------------------------------

    /**
     * The PHP footer injected #mmp-breadcrumb-root and #mmp-toolbar-root as
     * hidden elements. Once WP backbone renders its DOM, we move them to the
     * correct positions so the React portals appear in the right place.
     *
     *  #mmp-breadcrumb-root → prepended before .attachments inside .attachments-browser
     *  #mmp-toolbar-root    → inserted between .wp-filter and .attachments-browser
     */
    function positionPortalRoots() {
        var crumbRoot   = document.getElementById('mmp-breadcrumb-root');
        var toolbarRoot = document.getElementById('mmp-toolbar-root');

        // Breadcrumb bar — place directly before the attachments grid
        if (crumbRoot && crumbRoot.style.display === 'none') {
            var browser = document.querySelector('.attachments-browser');
            if (browser) {
                var grid = browser.querySelector('.attachments') || browser.firstChild;
                if (grid && grid.parentNode) {
                    grid.parentNode.insertBefore(crumbRoot, grid);
                } else {
                    browser.insertBefore(crumbRoot, browser.firstChild);
                }
                crumbRoot.style.display = '';
            }
        }

        // Toolbar — insert right after the WP filter bar
        if (toolbarRoot && toolbarRoot.style.display === 'none') {
            var wpFilter = document.querySelector('.wp-filter');
            if (wpFilter && wpFilter.parentNode) {
                wpFilter.parentNode.insertBefore(toolbarRoot, wpFilter.nextSibling);
                toolbarRoot.style.display = '';
            }
        }
    }

    // -------------------------------------------------------------------------
    // 4. Boot — retry until the WP media library backbone renders the grid
    // -------------------------------------------------------------------------

    var attempts = 0;
    var MAX_ATTEMPTS = 20;

    function tryBoot() {
        attachObserver();
        positionSidebarPortal();
        positionPortalRoots();

        var sidebarPlaced   = document.querySelector('[data-mmp-placed="1"]') !== null;
        var crumbRoot       = document.getElementById('mmp-breadcrumb-root');
        var toolbarRoot     = document.getElementById('mmp-toolbar-root');
        var crumbPlaced     = crumbRoot  && crumbRoot.style.display  !== 'none';
        var toolbarPlaced   = toolbarRoot && toolbarRoot.style.display !== 'none';

        var needsRetry = !observer ||
                         !sidebarPlaced ||
                         !crumbPlaced   ||
                         !toolbarPlaced;

        if (needsRetry && attempts < MAX_ATTEMPTS) {
            attempts++;
            setTimeout(tryBoot, 300);
        }
    }

    // Start after DOM ready and again after WP backbone renders.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', tryBoot);
    } else {
        tryBoot();
    }

    // WP fires 'ready' on the media frame when its views are attached.
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof wp !== 'undefined' && wp.media && wp.media.frame) {
            wp.media.frame.on('ready', function () {
                setTimeout(tryBoot, 100);
            });
        }
    });

    // -------------------------------------------------------------------------
    // 5. React → WP backbone folder filter bridge
    // -------------------------------------------------------------------------

    /**
     * When the React sidebar fires `mmp:folder-selected`, update the WP
     * backbone attachment collection props so the grid re-queries via AJAX
     * without a page reload.
     *
     * folderId: null  → All Files  (remove filter)
     * folderId: 0     → Uncategorized
     * folderId: N     → specific folder term ID
     */
    window.addEventListener('mmp:folder-selected', function (e) {
        if (typeof wp === 'undefined' || !wp.media || !wp.media.frame) return;

        var detail     = e.detail || {};
        var folderId   = detail.folderId  != null ? detail.folderId  : null;
        var unusedOnly = detail.unusedOnly ? '1' : null;

        try {
            var state   = wp.media.frame.state();
            var library = state ? state.get('library') : null;
            if (!library) return;

            // Reset both filters first.
            library.props.unset('mmp_folder_id');
            library.props.unset('mmp_unused');

            if (unusedOnly) {
                // "Unused Media" virtual node: pass mmp_unused=1 to the AJAX query.
                library.props.set({ mmp_unused: '1' });
            } else if (folderId !== null) {
                library.props.set({ mmp_folder_id: String(folderId) });
            }
            // folderId === null && !unusedOnly → All Files (no filter).

            // Force backbone to re-fetch attachments with the updated props.
            library.props.trigger('change');
        } catch (_) {
            // Media frame not in the expected state — ignore.
        }
    });

    // -------------------------------------------------------------------------
    // 6. React → WP backbone sort bridge (no page reload)
    // -------------------------------------------------------------------------

    /**
     * When the React toolbar fires `mmp:sort-change`, update the WP backbone
     * attachment collection's orderby / order props and trigger a re-fetch —
     * exactly the same mechanism used for folder filtering above.
     */
    window.addEventListener('mmp:sort-change', function (e) {
        if (typeof wp === 'undefined' || !wp.media || !wp.media.frame) return;

        var detail  = e.detail || {};
        var orderby = detail.orderby || 'date';
        var order   = detail.order   || 'DESC';

        try {
            var state   = wp.media.frame.state();
            var library = state ? state.get('library') : null;
            if (!library || !library.props) return;

            library.props.set({ orderby: orderby, order: order });
            library.props.trigger('change');
        } catch (_) {
            // Media frame not in the expected state — ignore.
        }
    });

    // -------------------------------------------------------------------------
    // 7. Usage warning badge — patch wp.media.view.Attachment to show ⚠ icon
    //    when mmp_used_in_published is true in the attachment model.
    // -------------------------------------------------------------------------

    (function patchAttachmentView() {
        if (typeof wp === 'undefined' || !wp.media || !wp.media.view) {
            // WP backbone not loaded yet — retry once it is.
            document.addEventListener('DOMContentLoaded', function () {
                setTimeout(patchAttachmentView, 500);
            });
            return;
        }

        var AttachmentView = wp.media.view.Attachment;
        if (!AttachmentView) return;

        var originalRender = AttachmentView.prototype.render;

        AttachmentView.prototype.render = function () {
            originalRender.apply(this, arguments);

            var el = this.el;
            if (!el) return this;

            // Remove stale badge if the model has been refreshed.
            var existing = el.querySelector('.mmp-usage-warning-badge');
            if (existing) existing.remove();

            if (this.model && this.model.get('mmp_used_in_published')) {
                var badge = document.createElement('span');
                badge.className = 'mmp-usage-warning-badge';
                badge.title     = '<?php echo esc_js(__('Used in published content — deleting may break pages', 'media-management-platform')); ?>';
                badge.textContent = '⚠';
                el.appendChild(badge);
                el.classList.add('mmp-has-published-usage');
            } else {
                el.classList.remove('mmp-has-published-usage');
            }

            return this;
        };
    }());

    // -------------------------------------------------------------------------
    // 8. Inline CSS for the usage warning badge
    // -------------------------------------------------------------------------

    (function injectBadgeStyles() {
        if (document.getElementById('mmp-usage-badge-styles')) return;
        var style = document.createElement('style');
        style.id  = 'mmp-usage-badge-styles';
        style.textContent = [
            '.mmp-usage-warning-badge {',
            '  position: absolute;',
            '  top: 4px;',
            '  right: 4px;',
            '  z-index: 10;',
            '  background: #d63638;',
            '  color: #fff;',
            '  font-size: 11px;',
            '  line-height: 1;',
            '  padding: 2px 4px;',
            '  border-radius: 3px;',
            '  pointer-events: none;',
            '}',
            '.attachment.mmp-has-published-usage .thumbnail {',
            '  outline: 2px solid #d63638;',
            '  outline-offset: -2px;',
            '}',
        ].join('\n');
        document.head.appendChild(style);
    }());
}());
</script>
        <?php
    }

    // -------------------------------------------------------------------------
    // Private Helpers
    // -------------------------------------------------------------------------

    /**
     * Retrieves a user's preferences from wp_mmp_user_prefs, returning
     * sensible defaults when no row exists.
     *
     * @param  int $userId  WordPress user ID.
     * @return array<string, mixed>
     */
    private function getUserPrefs(int $userId): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT folder_id, sort_files, sort_dir, sidebar_w, ui_theme
                 FROM {$wpdb->prefix}mmp_user_prefs
                 WHERE user_id = %d
                 LIMIT 1",
                $userId
            ),
            ARRAY_A
        );

        if (null === $row) {
            return $this->defaultPrefs();
        }

        return [
            'folder_id'  => isset($row['folder_id']) ? (int) $row['folder_id'] : null,
            'sort_files' => (string) ($row['sort_files'] ?? 'date'),
            'sort_dir'   => (string) ($row['sort_dir']   ?? 'desc'),
            'sidebar_w'  => (int)   ($row['sidebar_w']   ?? 220),
            'ui_theme'   => (string) ($row['ui_theme']   ?? 'default'),
        ];
    }

    /**
     * Returns the default user preferences matching the wp_mmp_user_prefs schema.
     *
     * @return array<string, mixed>
     */
    private function defaultPrefs(): array {
        /**
         * Filters the default active folder for a user who has no saved preference.
         *
         * Return a folder term ID (integer) to pre-select that folder when the
         * user opens the media library for the first time. Return null to show
         * "All Files".
         *
         * @since 1.0.0
         *
         * @param int|null $folderId  Default folder term ID. null = All Files.
         */
        $defaultFolder = apply_filters('mmp_user_default_folder', null);

        /**
         * Filters the default sort field for a user who has no saved preference.
         *
         * Valid values: 'date', 'modified', 'name', 'author', 'size'.
         *
         * @since 1.0.0
         *
         * @param string $sortField  Default sort field. Default: 'date'.
         */
        $defaultSortField = (string) apply_filters('mmp_user_default_sort', 'date');

        /**
         * Filters the default sort direction for a user who has no saved preference.
         *
         * Valid values: 'asc', 'desc'.
         *
         * @since 1.0.0
         *
         * @param string $sortDir  Default sort direction. Default: 'desc'.
         */
        $defaultSortDir = (string) apply_filters('mmp_user_default_sort_dir', 'desc');

        return [
            'folder_id'  => is_int($defaultFolder) ? $defaultFolder : null,
            'sort_files' => in_array($defaultSortField, ['date', 'modified', 'name', 'author', 'size'], true) ? $defaultSortField : 'date',
            'sort_dir'   => in_array($defaultSortDir, ['asc', 'desc'], true) ? $defaultSortDir : 'desc',
            'sidebar_w'  => 220,
            'ui_theme'   => 'default',
        ];
    }
}
