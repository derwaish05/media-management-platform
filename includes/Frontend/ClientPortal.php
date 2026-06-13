<?php

declare(strict_types=1);

namespace MMP\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MMP\Folder\FolderRepository;
use MMP\Taxonomy\FolderTaxonomy;

/**
 * Client Media Sharing Portal (S59).
 *
 * Registers the public-facing portal at:
 *   yoursite.com/mmp-portal/{token}/
 *
 * No WordPress login is required. The portal:
 *   - Validates the share token (existence + expiry).
 *   - Shows a password form if the link is password-protected.
 *   - Renders the shared folder's file tree (browse + preview + download).
 *   - Tracks every file download by visitor IP.
 *   - Applies branding (logo URL + header colour) stored on the share link.
 *
 * Download endpoint:
 *   yoursite.com/mmp-portal/{token}/download/{attachment_id}
 *
 * @package MMP\Frontend
 * @since   1.0.0
 */
class ClientPortal {

    /** Rewrite query-var that carries the share token. */
    public const QUERY_VAR = 'mmp_portal_token';

    /** Session key used to remember a verified password. */
    private const SESSION_KEY = 'mmp_portal_auth_';

    public function __construct(
        private readonly ShareLinkRepository $linkRepo,
        private readonly FolderRepository    $folderRepo,
    ) {}

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------

    public function register(): void {
        add_action( 'init',          [ $this, 'addRewriteRule'  ] );
        add_filter( 'query_vars',    [ $this, 'addQueryVar'     ] );
        add_action( 'template_redirect', [ $this, 'handleRequest' ] );
        add_action( 'mmp_portal_flush_rules', 'flush_rewrite_rules' );

        // Flush rewrite rules once after activation (deferred to next request).
        if ( ! get_option( 'mmp_portal_rules_flushed' ) ) {
            add_action( 'init', static function (): void {
                flush_rewrite_rules();
                update_option( 'mmp_portal_rules_flushed', 1 );
            }, 999 );
        }
    }

    // -------------------------------------------------------------------------
    // Rewrite
    // -------------------------------------------------------------------------

    public function addRewriteRule(): void {
        add_rewrite_rule(
            '^mmp-portal/([a-f0-9]{64})(?:/download/(\d+))?/?$',
            'index.php?' . self::QUERY_VAR . '=$matches[1]&mmp_portal_file=$matches[2]',
            'top'
        );
    }

    /**
     * @param  string[] $vars
     * @return string[]
     */
    public function addQueryVar( array $vars ): array {
        $vars[] = self::QUERY_VAR;
        $vars[] = 'mmp_portal_file';
        return $vars;
    }

    // -------------------------------------------------------------------------
    // Request router
    // -------------------------------------------------------------------------

    public function handleRequest(): void {
        $token = (string) get_query_var( self::QUERY_VAR );

        if ( '' === $token ) {
            return;
        }

        $link = $this->linkRepo->getByToken( $token );

        if ( null === $link ) {
            $this->renderError( 'Share link not found.', 404 );
        }

        if ( ! $this->linkRepo->isValid( $link ) ) {
            $this->renderError( 'This share link has expired.', 410 );
        }

        // Download sub-request.
        $fileId = (int) get_query_var( 'mmp_portal_file' );
        if ( $fileId > 0 ) {
            $this->handleDownload( $link, $fileId );
        }

        // Password gate.
        if ( ! empty( $link['password_hash'] ) ) {
            if ( ! $this->isAuthenticated( $link ) ) {
                if ( isset( $_POST['mmp_portal_password'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- public portal, nonce not applicable for external visitors
                    $this->handlePasswordSubmit( $link );
                }
                $this->renderPasswordGate( $link );
            }
        }

        $this->renderPortal( $link );
    }

    // -------------------------------------------------------------------------
    // Password gate
    // -------------------------------------------------------------------------

    private function isAuthenticated( array $link ): bool {
        if ( ! session_id() ) {
            @session_start();
        }

        $key = self::SESSION_KEY . $link['token'];
        return ! empty( $_SESSION[ $key ] );
    }

    private function handlePasswordSubmit( array $link ): void {
        if ( ! session_id() ) {
            @session_start();
        }

        $submitted = sanitize_text_field( wp_unslash( $_POST['mmp_portal_password'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- public portal, nonce not applicable

        if ( wp_check_password( $submitted, (string) $link['password_hash'] ) ) {
            $_SESSION[ self::SESSION_KEY . $link['token'] ] = true;
            wp_safe_redirect( $this->portalUrl( (string) $link['token'] ) );
            exit;
        }

        $this->renderPasswordGate( $link, true );
    }

    // -------------------------------------------------------------------------
    // Download handler
    // -------------------------------------------------------------------------

    private function handleDownload( array $link, int $attachmentId ): void {
        // Verify this attachment belongs to the shared folder subtree.
        $folderId = (int) $link['folder_id'];

        $terms = wp_get_object_terms( $attachmentId, FolderTaxonomy::TAXONOMY, [ 'fields' => 'ids' ] );
        $termIds = is_array( $terms ) ? array_map( 'intval', $terms ) : [];

        $allowedIds = $this->getAllowedFolderIds( $folderId );

        $inFolder = ! empty( array_intersect( $termIds, $allowedIds ) );

        // Files in the uncategorized folder (no terms) are also allowed if
        // the shared folder is the root.
        if ( ! $inFolder && ! ( empty( $termIds ) && $folderId === 0 ) ) {
            $this->renderError( 'File not found in this share.', 403 );
        }

        $filePath = (string) get_attached_file( $attachmentId );

        if ( ! $filePath || ! is_readable( $filePath ) ) {
            $this->renderError( 'File unavailable.', 404 );
        }

        // Log the download.
        $ip = $this->clientIp();
        $this->linkRepo->logDownload( (int) $link['id'], $attachmentId, $ip );

        // Stream file to browser.
        $filename = basename( $filePath );
        $mime     = (string) get_post_mime_type( $attachmentId );

        header( 'Content-Type: ' . ( $mime ?: 'application/octet-stream' ) );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . filesize( $filePath ) );
        header( 'Cache-Control: no-store' );

        readfile( $filePath ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_readfile, WordPress.WP.AlternativeFunctions.file_system_operations_readfile
        exit;
    }

    // -------------------------------------------------------------------------
    // Portal renderer
    // -------------------------------------------------------------------------

    private function renderPortal( array $link ): void {
        $folderId    = (int) $link['folder_id'];
        $folder      = $this->folderRepo->getById( $folderId );
        $folderName  = $folder ? (string) $folder['name'] : 'Shared Files';
        $logoUrl     = esc_url( (string) $link['logo_url'] );
        $headerColor = esc_attr( (string) ( $link['header_color'] ?: '#2563eb' ) );
        $token       = (string) $link['token'];

        // Build flat list of files in this folder (and sub-folders).
        $allowedIds  = $this->getAllowedFolderIds( $folderId );
        $files       = $this->getFiles( $allowedIds );

        $expiresAt   = ! empty( $link['expires_at'] )
            ? date_i18n( get_option( 'date_format' ), strtotime( (string) $link['expires_at'] ) )
            : null;

        $this->outputPortalHtml( $token, $folderName, $logoUrl, $headerColor, $files, $expiresAt );
    }

    private function outputPortalHtml(
        string  $token,
        string  $folderName,
        string  $logoUrl,
        string  $headerColor,
        array   $files,
        ?string $expiresAt
    ): void {
        $siteTitle = esc_html( get_bloginfo( 'name' ) );
        $pageTitle = esc_html( $folderName ) . ' — ' . $siteTitle;

        status_header( 200 );
        header( 'Content-Type: text/html; charset=utf-8' );

        ?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_locale() ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $pageTitle; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $pageTitle built from esc_html() calls above ?></title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f1f5f9; color: #1e293b; }
.portal-header { background: <?php echo $headerColor; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $headerColor is esc_attr() processed ?>; color: #fff; padding: 16px 24px; display: flex; align-items: center; gap: 16px; }
.portal-header img { height: 40px; width: auto; border-radius: 4px; }
.portal-header h1 { font-size: 20px; font-weight: 600; }
.portal-meta { font-size: 12px; opacity: .75; margin-top: 2px; }
.portal-body { max-width: 1100px; margin: 32px auto; padding: 0 16px; }
.file-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; }
.file-card { background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.08); transition: box-shadow .15s; }
.file-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,.12); }
.file-preview { width: 100%; aspect-ratio: 4/3; object-fit: cover; display: block; background: #e2e8f0; }
.file-preview-icon { width: 100%; aspect-ratio: 4/3; display: flex; align-items: center; justify-content: center; background: #e2e8f0; font-size: 40px; }
.file-info { padding: 10px 12px; }
.file-name { font-size: 13px; font-weight: 500; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.file-meta { font-size: 11px; color: #94a3b8; margin-top: 2px; }
.file-dl { display: block; margin-top: 8px; padding: 6px 12px; background: <?php echo $headerColor; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $headerColor is esc_attr() processed ?>; color: #fff; border-radius: 4px; text-align: center; text-decoration: none; font-size: 12px; font-weight: 600; transition: opacity .15s; }
.file-dl:hover { opacity: .85; }
.empty { text-align: center; padding: 64px 24px; color: #94a3b8; }
footer { text-align: center; padding: 24px; font-size: 11px; color: #94a3b8; }
</style>
</head>
<body>

<header class="portal-header">
  <?php if ( $logoUrl ) : ?>
    <img src="<?php echo $logoUrl; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $logoUrl is esc_url() processed ?>" alt="Logo">
  <?php endif; ?>
  <div>
    <h1><?php echo esc_html( $folderName ); ?></h1>
    <p class="portal-meta">
      Shared by <?php echo esc_html( get_bloginfo( 'name' ) ); ?>
      <?php if ( $expiresAt ) : ?>
        &nbsp;·&nbsp; Expires <?php echo esc_html( $expiresAt ); ?>
      <?php endif; ?>
    </p>
  </div>
</header>

<div class="portal-body">
  <?php if ( empty( $files ) ) : ?>
    <p class="empty">No files in this shared folder yet.</p>
  <?php else : ?>
    <div class="file-grid">
      <?php foreach ( $files as $file ) :
        $isImage = str_starts_with( (string) $file['mime_type'], 'image/' );
        $dlUrl   = esc_url( $this->portalUrl( $token ) . 'download/' . $file['id'] . '/' );
      ?>
        <div class="file-card">
          <?php if ( $isImage ) : ?>
            <a href="<?php echo esc_url( (string) $file['url'] ); ?>" target="_blank" rel="noopener">
              <img class="file-preview" src="<?php echo esc_url( (string) $file['thumbnail'] ); ?>" alt="<?php echo esc_attr( (string) $file['alt'] ); ?>" loading="lazy">
            </a>
          <?php else : ?>
            <div class="file-preview-icon" title="<?php echo esc_attr( (string) $file['mime_type'] ); ?>">
              <?php echo $this->mimeIcon( (string) $file['mime_type'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- mimeIcon() returns trusted hardcoded HTML ?>
            </div>
          <?php endif; ?>
          <div class="file-info">
            <p class="file-name" title="<?php echo esc_attr( (string) $file['title'] ); ?>">
              <?php echo esc_html( (string) $file['title'] ); ?>
            </p>
            <p class="file-meta"><?php echo esc_html( (string) $file['size_human'] ); ?></p>
            <a class="file-dl" href="<?php echo $dlUrl; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $dlUrl is esc_url() processed ?>">Download</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<footer>
  <p>Powered by <?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
</footer>

</body>
</html>
        <?php

        exit;
    }

    // -------------------------------------------------------------------------
    // Password gate renderer
    // -------------------------------------------------------------------------

    private function renderPasswordGate( array $link, bool $wrongPassword = false ): void {
        $headerColor = esc_attr( (string) ( $link['header_color'] ?: '#2563eb' ) );

        status_header( 200 );
        header( 'Content-Type: text/html; charset=utf-8' );

        ?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_locale() ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Protected Share — <?php echo esc_html( get_bloginfo( 'name' ) ); ?></title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f1f5f9; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
.gate { background: #fff; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,.1); padding: 40px 32px; width: 100%; max-width: 380px; text-align: center; }
.gate-icon { font-size: 40px; margin-bottom: 16px; }
h1 { font-size: 20px; font-weight: 600; color: #1e293b; margin-bottom: 8px; }
p { font-size: 14px; color: #64748b; margin-bottom: 24px; }
input[type=password] { width: 100%; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px; margin-bottom: 12px; outline: none; }
input[type=password]:focus { border-color: <?php echo $headerColor; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $headerColor is esc_attr() processed ?>; box-shadow: 0 0 0 2px <?php echo $headerColor; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>33; }
button { width: 100%; padding: 10px; background: <?php echo $headerColor; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $headerColor is esc_attr() processed ?>; color: #fff; border: none; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; }
button:hover { opacity: .9; }
.error { color: #ef4444; font-size: 13px; margin-bottom: 12px; }
</style>
</head>
<body>
<div class="gate">
  <div class="gate-icon">🔒</div>
  <h1>Password Required</h1>
  <p>This shared folder is password-protected.</p>
  <?php if ( $wrongPassword ) : ?>
    <p class="error">Incorrect password. Please try again.</p>
  <?php endif; ?>
  <form method="post">
    <?php wp_nonce_field( 'mmp_portal_pw' ); ?>
    <input type="password" name="mmp_portal_password" placeholder="Enter password…" required autofocus>
    <button type="submit">Unlock</button>
  </form>
</div>
</body>
</html>
        <?php

        exit;
    }

    // -------------------------------------------------------------------------
    // Error page
    // -------------------------------------------------------------------------

    private function renderError( string $message, int $status = 404 ): never {
        status_header( $status );
        header( 'Content-Type: text/html; charset=utf-8' );
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Error</title></head><body>';
        echo '<p style="font-family:sans-serif;padding:32px;color:#64748b;">' . esc_html( $message ) . '</p>';
        echo '</body></html>';
        exit;
    }

    // -------------------------------------------------------------------------
    // URL helpers
    // -------------------------------------------------------------------------

    /**
     * Canonical URL for a portal page.
     */
    public static function portalUrl( string $token ): string {
        return home_url( "/mmp-portal/{$token}/" );
    }

    // -------------------------------------------------------------------------
    // File helpers
    // -------------------------------------------------------------------------

    /**
     * Recursively collect all folder IDs in the subtree rooted at $folderId.
     *
     * @return int[]
     */
    private function getAllowedFolderIds( int $folderId ): array {
        $ids  = [ $folderId ];
        $tree = $this->folderRepo->getTree( 0 );
        $this->collectDescendants( $tree, $folderId, $ids );
        return $ids;
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @param int[]                            $ids    (accumulated)
     */
    private function collectDescendants( array $nodes, int $parentId, array &$ids ): void {
        foreach ( $nodes as $node ) {
            if ( (int) $node['parent'] === $parentId ) {
                $ids[] = (int) $node['id'];
                $this->collectDescendants( $nodes, (int) $node['id'], $ids );
            }
            if ( ! empty( $node['children'] ) ) {
                $this->collectDescendants( (array) $node['children'], $parentId, $ids );
            }
        }
    }

    /**
     * Return attachment data for files belonging to $allowedFolderIds.
     *
     * @param  int[]                            $allowedFolderIds
     * @return array<int, array<string, mixed>>
     */
    private function getFiles( array $allowedFolderIds ): array {
        $query = new \WP_Query( [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
            'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                [
                    'taxonomy' => FolderTaxonomy::TAXONOMY,
                    'field'    => 'term_id',
                    'terms'    => $allowedFolderIds,
                    'operator' => 'IN',
                ],
            ],
        ] );

        $files = [];

        foreach ( $query->posts as $post ) {
            if ( ! ( $post instanceof \WP_Post ) ) {
                continue;
            }

            $url       = (string) wp_get_attachment_url( $post->ID );
            $thumb     = wp_get_attachment_image_url( $post->ID, 'medium' ) ?: $url;
            $meta      = wp_get_attachment_metadata( $post->ID );
            $fileSize  = is_array( $meta ) && isset( $meta['filesize'] )
                ? (int) $meta['filesize']
                : (int) get_post_meta( $post->ID, '_wp_attachment_filesize', true );

            $files[] = [
                'id'        => $post->ID,
                'title'     => $post->post_title ?: basename( $url ),
                'url'       => $url,
                'thumbnail' => $thumb,
                'mime_type' => (string) $post->post_mime_type,
                'alt'       => (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
                'size_human' => $fileSize > 0 ? size_format( $fileSize ) : '—',
            ];
        }

        return $files;
    }

    /**
     * Return a simple emoji icon for common MIME type groups.
     */
    private function mimeIcon( string $mime ): string {
        return match( true ) {
            str_starts_with( $mime, 'video/' )            => '🎬',
            str_starts_with( $mime, 'audio/' )            => '🎵',
            $mime === 'application/pdf'                    => '📄',
            str_contains( $mime, 'spreadsheet' )
                || str_contains( $mime, 'excel' )         => '📊',
            str_contains( $mime, 'word' )
                || str_contains( $mime, 'document' )      => '📝',
            str_contains( $mime, 'zip' )
                || str_contains( $mime, '7z' )
                || str_contains( $mime, 'rar' )           => '🗜️',
            default                                        => '📁',
        };
    }

    /**
     * Return the visitor's IP address.
     */
    private function clientIp(): string {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];

        foreach ( $headers as $h ) {
            $val = sanitize_text_field( wp_unslash( $_SERVER[ $h ] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- checked for emptiness immediately below
            if ( '' !== $val ) {
                // X-Forwarded-For can be a comma-separated list; take the first entry.
                return trim( explode( ',', $val )[0] );
            }
        }

        return '';
    }
}
