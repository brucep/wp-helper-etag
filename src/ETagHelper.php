<?php

namespace Brucep\WordPress\ETagHelper;

use Brucep\WordPress\RedisHelper\RedisHelper;

class ETagHelper
{
    /**
     * Hook this method in your custom WordPress plugin.
     *
     * <code>
     * add_action('parse_request', [ETagHelper::class, 'parseRequest']);
     * </code>
     *
     * Use the query_vars filter when using GET query variables to determine output:
     *
     * <code>
     * add_filter('query_vars', function (array $vars): array {
     *     global $wp;
     *
     *     prase_str($wp->matched_query, $query);
     *     $query['post_type'] ??= null;
     *
     *     if ('example-page' === $wp->request) {
     *         $vars[] = 'foo';
     *     } elseif ('AcmeCustomPostType' === $query['post_type']) {
     *         $vars[] = 'bar';
     *     }
     *
     *     return $vars;
     *  });
     * </code>
     *
     * @see https://developer.wordpress.org/reference/hooks/query_vars/
     */
    public static function parseRequest(\WP $wp): void
    {
        if (null === $cache = RedisHelper::getAdapter()) {
            return;
        }

        $cacheItem = $cache->getItem(sprintf(
            '%s;%s',
            defined('BPWP_ETAG_CACHE_KEY') ? BPWP_ETAG_CACHE_KEY : 'etag',
            md5($wp->request.serialize($wp->query_vars))
        ));
        $clientETag = $_SERVER['HTTP_IF_NONE_MATCH'] ?? null;

        if ($cacheItem->isHit() && $cacheItem->get() === $clientETag) {
            header('HTTP/1.1 304 Not Modified');
            exit;
        }

        $tags = [defined('BPWP_ETAG_CACHE_TAG') ? BPWP_ETAG_CACHE_TAG : 'etag'];
        if (isset($wp->query_vars['post_type'])) {
            $tags[] = $wp->query_vars['post_type'];
        }
        $cacheItem->tag($tags);

        define('BPWP_ETAG_CACHE_ITEM', serialize($cacheItem));
    }

    /**
     * Use this method to set the ETag header based on content of your choosing.
     *
     * The likely place to do this would be in a custom theme. You should consider
     * invalidating these cache items via the save_post hook.
     *
     * @see https://developer.wordpress.org/reference/hooks/save_post/
     */
    public static function setETag(string $eTag, $cacheItemTags = []): void
    {
        if (null === $cache = RedisHelper::getAdapter()) {
            return;
        }

        if (!defined('BPWP_ETAG_CACHE_ITEM')) {
            throw new \LogicException(sprintf('Did you hook %s::parseRequest() to the parse_query action?', __CLASS__));
        }

        if (headers_sent()) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                throw new \LogicException('Headers were already sent before setting ETag.');
            } else {
                return;
            }
        }

        $cacheItem = unserialize(BPWP_ETAG_CACHE_ITEM);
        $cacheItem->set($eTag);
        header(sprintf('ETag: "%s"', urlencode($eTag)));

        if (defined('BPWP_ETAG_MAX_AGE') && is_int(BPWP_ETAG_MAX_AGE)) {
            header(sprintf('Cache-Control: max-age=%d', BPWP_ETAG_MAX_AGE));
        }

        if (!empty($cacheItemTags)) {
            $cacheItem->tag($cacheItemTags);
        }

        $cache->save($cacheItem);
    }

    private function construct()
    {
    }
}
