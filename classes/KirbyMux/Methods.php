<?php

namespace KirbyMux;

use Kirby\Cms\File;
use Kirby\Http\Response;
use MuxPhp;

class Methods
{
    public static function upload($assetsApi, $url, ?File $file = null)
    {
        $source = option('tristantbg.kirby-mux.dev') ? "https://storage.googleapis.com/muxdemofiles/mux-video-intro.mp4" : $url;
        $input = new MuxPhp\Models\InputSettings(["url" => $source]);
        $params = [
            "input" => $input,
            "playback_policy" => [MuxPhp\Models\PlaybackPolicy::_PUBLIC],
            'static_renditions' => [
                new MuxPhp\Models\CreateStaticRenditionRequest(['resolution' => '270p']),
                new MuxPhp\Models\CreateStaticRenditionRequest(['resolution' => '720p']),
                new MuxPhp\Models\CreateStaticRenditionRequest(['resolution' => '1080p']),
            ]
        ];

        // Store the Kirby file id in the Mux asset so incoming webhooks can be
        // mapped back to the right file without any extra API lookups.
        if ($file !== null) {
            $params['passthrough'] = $file->id();
        }

        $createAssetRequest = new MuxPhp\Models\CreateAssetRequest($params);

        return $assetsApi->createAsset($createAssetRequest);
    }

    /**
     * Read the stored Mux data for a file. Does not call the Mux API; the
     * stored data is kept up to date by the Mux webhook handler instead.
     */
    public static function ensureRenditionsReady(File $file): object
    {
        return json_decode($file->mux());
    }

    /**
     * Save a Mux thumbnail to disk if it does not exist yet.
     */
    public static function saveThumbnail(File $file, string $playbackId, ?float $time = null): void
    {
        $target = $file->parent()->root() . '/' . $file->name() . '-thumbnail.jpg';
        if (file_exists($target)) {
            return;
        }

        $url = "https://image.mux.com/" . $playbackId . "/thumbnail.jpg";
        if ($time !== null) {
            $url .= '?time=' . $time;
        }
        $imagedata = file_get_contents($url);
        \F::write($target, $imagedata);
    }

    /**
     * Store the initial asset data after upload. Further processing (thumbnail,
     * renditions, optional disk optimization) is finished asynchronously by the
     * Mux webhook, so this method never blocks or polls the API.
     */
    public static function processAfterUpload($assetsApi, File $file, $result): File
    {
        return $file->update(['mux' => $result->getData()]);
    }

    /**
     * Refetch the Mux data for a single file directly from the Mux API.
     *
     * - If the file already has a stored asset id, the latest asset data is
     *   pulled with a single `getAsset` call and persisted. If the asset's
     *   static renditions are `disabled`, they are re-enabled (270p, 720p,
     *   1080p) before the data is persisted.
     * - If the file has no Mux data at all, the file is (re-)uploaded to Mux,
     *   creating a fresh asset with the passthrough so future webhooks resolve.
     *
     * Unlike the webhook flow this performs a direct API call, so it should be
     * triggered manually (e.g. from the Panel) to recover missing data.
     *
     * @return array<string, mixed> The refreshed Panel-facing video data.
     */
    public static function refetch(string $id): array
    {
        $kirby = kirby();
        $kirby->impersonate('kirby');

        $file = $kirby->file($id);
        if (!$file) {
            throw new \Kirby\Exception\NotFoundException('File not found: ' . $id);
        }

        $assetsApi = Auth::assetsApi();

        $raw     = $file->mux()->isNotEmpty() ? json_decode($file->mux(), true) : null;
        $assetId = is_array($raw) ? ($raw['id'] ?? null) : null;

        if ($assetId) {
            // Pull the latest asset payload from Mux and persist it.
            $response = $assetsApi->getAsset($assetId);
            $data     = $response->getData();

            // If static renditions were disabled, re-enable them so the MP4
            // download URLs become available again.
            if (($data['static_renditions']['status'] ?? null) === 'disabled') {
                static::enableStaticRenditions($assetsApi, $assetId, $data);
                $data = $assetsApi->getAsset($assetId)->getData();
            }

            $file = $file->update(['mux' => $data]);
        } else {
            // No asset stored yet: create a fresh Mux asset from the file.
            $result = static::upload($assetsApi, $file->url(), $file);
            $file   = static::processAfterUpload($assetsApi, $file, $result);
        }

        // Save the thumbnail locally once the asset is ready.
        $data       = json_decode($file->mux(), true);
        $playbackId = $data['playback_ids'][0]['id'] ?? null;
        if (($data['status'] ?? null) === 'ready' && $playbackId) {
            static::saveThumbnail($file, $playbackId);
        }

        return static::videoData($file);
    }

    /**
     * Request the standard static MP4 renditions (270p, 720p, 1080p) for an
     * existing Mux asset. Used to re-enable renditions that were disabled.
     *
     * Assets created with the deprecated `mp4_support` parameter cannot have a
     * `static_renditions` array at the same time, so `mp4_support` is disabled
     * first when present. Resolutions higher than the source are skipped by Mux
     * (not an error). If every request fails for a real reason, the combined
     * error is thrown so the caller can surface it.
     *
     * @param array<string, mixed>|\MuxPhp\Models\Asset|null $asset The current
     *        asset payload (array or Mux model), used to detect legacy
     *        `mp4_support`.
     */
    public static function enableStaticRenditions($assetsApi, string $assetId, $asset = null): void
    {
        // The deprecated `mp4_support` parameter and the `static_renditions`
        // array cannot coexist on an asset. Disable it first when present.
        $mp4Support = $asset['mp4_support'] ?? null;
        if (!empty($mp4Support) && $mp4Support !== 'none') {
            $assetsApi->updateAssetMp4Support(
                $assetId,
                new MuxPhp\Models\UpdateAssetMP4SupportRequest(['mp4_support' => 'none'])
            );
        }

        $resolutions = ['270p', '720p', '1080p'];
        $errors = [];

        foreach ($resolutions as $resolution) {
            $request = new MuxPhp\Models\CreateStaticRenditionRequest(['resolution' => $resolution]);
            try {
                $assetsApi->createAssetStaticRendition($assetId, $request);
            } catch (\Exception $e) {
                // A rendition at this resolution may already exist; that is not
                // a failure. Collect any other error to surface later.
                if (stripos($e->getMessage(), 'already') === false) {
                    $errors[] = $resolution . ': ' . $e->getMessage();
                }
            }
        }

        // Only treat it as a failure if no rendition could be requested at all.
        if (count($errors) === count($resolutions)) {
            throw new \Exception('Could not enable static renditions — ' . implode('; ', $errors));
        }
    }

    /**
     * Build the Mux dashboard asset URL.
     *
     * When both an `organizationId` and `environmentId` are configured the URL
     * deep-links straight to the asset. Otherwise it falls back to the general
     * Mux dashboard so the link is still shown (Mux redirects to the user's
     * default organization/environment).
     */
    public static function dashboardUrl(?string $assetId): ?string
    {
        if (empty($assetId)) {
            return null;
        }

        $organizationId = option('tristantbg.kirby-mux.organizationId');
        $environmentId  = option('tristantbg.kirby-mux.environmentId');

        if (empty($organizationId) || empty($environmentId)) {
            return 'https://dashboard.mux.com/';
        }

        return "https://dashboard.mux.com/organizations/{$organizationId}/environments/{$environmentId}/video/assets/{$assetId}";
    }

    /**
     * Collect all Mux video files across the site for the Panel view.
     * Reads stored data only and never calls the Mux API.
     *
     * @return array{videos: array<int, array<string, mixed>>, stats: array<string, int>}
     */
    public static function panelVideos(): array
    {
        $kirby = kirby();
        $kirby->impersonate('kirby');

        $videos = [];

        $collect = function ($files) use (&$videos) {
            foreach ($files as $file) {
                if ($file->template() !== 'mux-video' && $file->type() !== 'video') {
                    continue;
                }
                $videos[] = static::videoData($file);
            }
        };

        // Site-level files.
        $collect($kirby->site()->files());

        // Files attached to every page (including drafts).
        foreach ($kirby->site()->index(true) as $page) {
            $collect($page->files());
        }

        // Newest first, based on the file's last modification time.
        usort($videos, fn($a, $b) => ($b['modified'] ?? 0) <=> ($a['modified'] ?? 0));

        $stats = [
            'total'   => count($videos),
            'ready'   => count(array_filter($videos, fn($v) => $v['status'] === 'ready')),
            'missing' => count(array_filter($videos, fn($v) => $v['hasMuxData'] === false)),
        ];

        return ['videos' => $videos, 'stats' => $stats];
    }

    /**
     * Build the Panel-facing data array for a single video file.
     *
     * @return array<string, mixed>
     */
    public static function videoData(File $file): array
    {
        $raw = $file->mux()->isNotEmpty() ? json_decode($file->mux(), true) : null;
        $mux = is_array($raw) ? $raw : null;

        $assetId         = $mux['id'] ?? null;
        $status          = $mux['status'] ?? null;
        $playbackId      = $mux['playback_ids'][0]['id'] ?? null;
        $renditions      = $mux['static_renditions']['status'] ?? null;
        $hasMuxData      = $assetId !== null;

        $parent = $file->parent();

        return [
            'id'               => $file->id(),
            'filename'         => $file->filename(),
            'panelUrl'         => $file->panel()->url(true),
            'parentTitle'      => method_exists($parent, 'title') ? (string) $parent->title() : 'Site',
            'parentUrl'        => method_exists($parent, 'panel') ? $parent->panel()->url(true) : null,
            'modified'         => $file->modified(),
            'hasMuxData'       => $hasMuxData,
            'assetId'          => $assetId,
            'status'           => $hasMuxData ? ($status ?? 'unknown') : 'missing',
            'playbackId'       => $playbackId,
            'renditionsStatus' => $renditions,
            'thumbnail'        => $playbackId ? "https://image.mux.com/{$playbackId}/thumbnail.jpg?width=160" : null,
            'streamUrl'        => $playbackId ? "https://stream.mux.com/{$playbackId}.m3u8" : null,
            'dashboardUrl'     => static::dashboardUrl($assetId),
        ];
    }

    /**
     * Handle an incoming Mux webhook: verify the signature, find the matching
     * Kirby file via the asset passthrough, and persist the latest asset data.
     */
    public static function handleWebhook(): Response
    {
        $rawBody = file_get_contents('php://input');
        $secret  = option('tristantbg.kirby-mux.webhookSecret');

        if (empty($secret)) {
            return new Response('Webhook secret not configured', 'text/plain', 500);
        }

        $signatureHeader = $_SERVER['HTTP_MUX_SIGNATURE'] ?? '';
        if (!static::verifySignature($rawBody, $signatureHeader, $secret)) {
            return new Response('Invalid signature', 'text/plain', 403);
        }

        $event = json_decode($rawBody, true);
        $type  = $event['type'] ?? '';
        $data  = $event['data'] ?? [];
        $passthrough = $data['passthrough'] ?? null;

        // We only care about asset lifecycle events that carry asset data.
        if (strpos($type, 'video.asset.') !== 0 || empty($data['id'])) {
            return new Response('Ignored', 'text/plain', 200);
        }

        if (empty($passthrough)) {
            // No passthrough means the asset was created before this version of
            // the plugin. Acknowledge so Mux does not keep retrying.
            return new Response('No passthrough', 'text/plain', 200);
        }

        $kirby = kirby();
        $kirby->impersonate('kirby');

        $file = $kirby->file($passthrough);
        if (!$file) {
            return new Response('File not found', 'text/plain', 200);
        }

        // Persist the latest asset payload as the file's mux data.
        $file = $file->update(['mux' => json_encode($data)]);

        $status          = $data['status'] ?? null;
        $playbackId      = $data['playback_ids'][0]['id'] ?? null;
        $renditionsReady = ($data['static_renditions']['status'] ?? null) === 'ready';

        if ($status === 'ready' && $playbackId) {
            static::saveThumbnail($file, $playbackId);
        }

        if ($renditionsReady && option('tristantbg.kirby-mux.optimizeDiskSpace', false)) {
            $videodata = file_get_contents($file->muxUrlLow());
            \F::write($file->parent()->root() . '/' . $file->name() . '.mp4', $videodata);
        }

        return new Response('OK', 'text/plain', 200);
    }

    /**
     * Verify a Mux webhook signature header (`Mux-Signature: t=...,v1=...`).
     * Uses HMAC-SHA256 over "{timestamp}.{rawBody}" and a constant-time compare.
     */
    private static function verifySignature(string $payload, string $header, string $secret): bool
    {
        if ($header === '') {
            return false;
        }

        $timestamp = null;
        $signature = null;
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }
            [$key, $value] = $pair;
            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signature = $value;
            }
        }

        if ($timestamp === null || $signature === null) {
            return false;
        }

        // Reject events older than 5 minutes to mitigate replay attacks.
        if (abs(time() - (int)$timestamp) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

        return hash_equals($expected, $signature);
    }
}
