<?php

namespace App\Services\Trunkrs;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class TrunkrsGraphClient
{
    private string $token;

    private float $deadline;

    public function __construct(private TrunkrsMicrosoftAuth $auth) {}

    public function connect(): void
    {
        $this->deadline = microtime(true) + 240;
        $this->token = $this->auth->accessToken();
    }

    private function folderUrl(): string
    {
        $mailboxPath = $this->auth->usesOwnMailbox() ? 'me' : 'users/'.rawurlencode(config('trunkrs.mailbox'));

        return 'https://graph.microsoft.com/v1.0/'.$mailboxPath
            .'/mailFolders/'.rawurlencode(config('trunkrs.folder_id'));
    }

    public function messages(): \Generator
    {
        $since = CarbonImmutable::now('UTC')->subDays(config('trunkrs.lookback_days'))->format('Y-m-d\TH:i:s\Z');
        // Never request a mailbox-wide message list or message body.
        yield from $this->pages($this->folderUrl().'/messages', [
            '$select' => 'id,receivedDateTime,from,subject,hasAttachments',
            '$filter' => "receivedDateTime ge $since", '$orderby' => 'receivedDateTime desc', '$top' => 50,
        ]);
    }

    public function attachment(array $message): array
    {
        $url = $this->folderUrl().'/messages/'.rawurlencode($message['id']).'/attachments';
        $attachments = iterator_to_array($this->pages($url, ['$select' => 'id,name,size,isInline'], 4));
        $matches = array_values(array_filter($attachments, fn ($item) => ! ($item['isInline'] ?? false)
            && ($item['@odata.type'] ?? '') === '#microsoft.graph.fileAttachment'
            && in_array(strtolower(pathinfo($item['name'] ?? '', PATHINFO_EXTENSION)), ['csv', 'zip'], true)));
        if (count($matches) !== 1) {
            throw new TrunkrsException('missing_attachment');
        }
        $file = $matches[0];
        if (($file['size'] ?? 0) > TrunkrsReportParser::MAX_BYTES || empty($file['id'])) {
            throw new TrunkrsException('invalid_report');
        }
        $bytes = $this->get($url.'/'.rawurlencode($file['id']).'/$value', [], true);

        return ['bytes' => $bytes, 'name' => $file['name']];
    }

    private function pages(string $url, array $query, int $maxPages = 20): \Generator
    {
        $base = $url;
        for ($page = 0; $page < $maxPages; $page++) {
            $data = $this->get($url, $query);
            if (! is_array($data['value'] ?? null)) {
                throw new TrunkrsException('provider');
            }
            foreach ($data['value'] as $item) {
                if (! is_array($item)) {
                    throw new TrunkrsException('provider');
                }
                yield $item;
            }
            $url = $data['@odata.nextLink'] ?? null;
            if ($url === null) {
                return;
            }
            // A provider link must stay on exactly this folder/resource. Never follow external URLs.
            if (! is_string($url) || explode('?', $url, 2)[0] !== $base) {
                throw new TrunkrsException('provider');
            }
            $query = [];
        }
        throw new TrunkrsException('page_limit');
    }

    private function get(string $url, array $query, bool $binary = false): mixed
    {
        if (microtime(true) >= $this->deadline) {
            throw new TrunkrsException('page_limit');
        }
        try {
            $request = Http::withToken($this->token)->withHeaders(['Prefer' => 'IdType="ImmutableId"'])
                ->connectTimeout(5)->timeout(20)->withoutRedirecting()
                ->withOptions(['progress' => function ($total, $downloaded) {
                    if ($total > 4_000_000 || $downloaded > 4_000_000) {
                        throw new TrunkrsException('invalid_report');
                    }
                }]);
            // Passing [] as Guzzle query would erase the provider's nextLink skip token.
            $response = $query ? $request->get($url, $query) : $request->get($url);
        } catch (ConnectionException) {
            throw new TrunkrsException('network');
        }
        if ($response->status() === 429) {
            throw new TrunkrsException('rate_limit', max(600, min(86400, (int) $response->header('Retry-After'))));
        }
        if (in_array($response->status(), [401, 403], true)) {
            throw new TrunkrsException('authorization');
        }
        if (! $response->successful()) {
            throw new TrunkrsException('provider');
        }
        if ($binary) {
            return $response->body();
        }
        $json = $response->json();

        return is_array($json) ? $json : throw new TrunkrsException('provider');
    }
}
