<?php

namespace App;

use DateTime;
use DateTimeInterface;
use PierreMiniggio\DatabaseConnection\DatabaseConnection;
use PierreMiniggio\DatabaseFetcher\DatabaseFetcher;
use PierreMiniggio\GoogleTokenRefresher\AccessTokenProvider;

class App
{

    public function __construct(
        private string $baseDir,
        private string $host
    )
    {
    }

    public function run(string $path, ?string $queryParameters): void
    {
        if ($path === '/') {
            http_response_code(404);

            return;
        }

        $request = substr($path, 1);

        if (strlen($request) !== 11) {
            http_response_code(404);

            return;
        }

        $videoId = $request;

        $config = require __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'config.php';
        $apiConfig = $config['api'];
        $dbConfig = $config['db'];
        $fetcher = new DatabaseFetcher(new DatabaseConnection(
            $dbConfig['host'],
            $dbConfig['database'],
            $dbConfig['username'],
            $dbConfig['password'],
            DatabaseConnection::UTF8_MB4
        ));

        $queriedIds = $fetcher->query(
            $fetcher
                ->createQuery('unprocessable_request')
                ->select('id')
                ->where('request = :request')
            ,
            ['request' => $videoId]
        );

        if ($queriedIds) {
            http_response_code(404);

            return;
        }

        $videoInfos = $this->findVideoInfosIfPresent($fetcher, $videoId);

        if ($videoInfos) {
            http_response_code(200);
            echo json_encode($videoInfos);

            return;
        }

        $provider = new AccessTokenProvider();
        $accessToken = $provider->get(
            $apiConfig['client_id'],
            $apiConfig['client_secret'],
            $apiConfig['refresh_token']
        );

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL => 'https://www.googleapis.com/youtube/v3/videos?id=' . $videoId . '&part=snippet'
        ]);
        $authorization = 'Authorization: Bearer ' . $accessToken;
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json' , $authorization]);

        $result = curl_exec($curl);

        if ($result === false) {
            http_response_code(500);

            return;
        }

        $jsonResponse = json_decode($result, true);
        if (! empty($jsonResponse['error'])) {
            http_response_code(500);

            return;
        }

        if (empty($jsonResponse['pageInfo']) || empty($jsonResponse['pageInfo']['totalResults'])) {
            $fetcher->exec(
                $fetcher
                    ->createQuery('unprocessable_request')
                    ->insertInto('request', ':video_id')
                ,
                ['video_id' => $videoId]
            );
            http_response_code(404);

            return;
        }

        if (empty($jsonResponse['items'])) {
            http_response_code(500);

            return;
        }

        $entry = $jsonResponse['items'][0];
        $snippet = $entry['snippet'];

        $channelId = $snippet['channelId'] ?? null;
        $title = $snippet['title'] ?? null;
        $description = $snippet['description'] ?? null;
        $categoryId = $snippet['categoryId'] ?? null;
        $thumbnails = $snippet['thumbnails'] ?? null;
        $thumbnail = null;

        if ($thumbnails) {
            $thumbnailTypes = array_reverse(array_keys($thumbnails));
            
            foreach ($thumbnailTypes as $thumbnailType) {
                if (
                    ! isset($thumbnails[$thumbnailType])
                    || ! isset($thumbnails[$thumbnailType]['url'])
                ) {
                    continue;
                }

                $thumbnail = $thumbnails[$thumbnailType]['url'];
                break;
            }
        }

        $publishedAt = $snippet['publishedAt'] ?? null;
        $publishedAtDate = ! empty($publishedAt)
            ? DateTime::createFromFormat(DateTimeInterface::ISO8601, $publishedAt)
            : null
        ;

        $fetcher->exec(
            $fetcher->createQuery(
                'video_info'
            )->insertInto(
                'video_id, channel_id, title, description, category_id, thumbnail, published_at',
                ':video_id, :channel_id, :title, :description, :category_id, :thumbnail, :publishedAt'
            ),
            [
                'video_id' => $videoId,
                'channel_id' => $channelId,
                'title' => $title,
                'description' => $description,
                'category_id' => $categoryId,
                'thumbnail' => $thumbnail,
                'publishedAt' => $publishedAtDate ? $publishedAtDate->format('Y-m-d H:i:s') : null
            ]
        );
        
        $videoInfos = $this->findVideoInfosIfPresent($fetcher, $videoId);

        if (! $videoInfos) {
            http_response_code(500);

            return;
        }

        http_response_code(200);
        echo json_encode($videoInfos);
    }

    protected function findVideoInfosIfPresent(DatabaseFetcher $fetcher, string $videoId): ?array
    {
        $fetchedVideos = $fetcher->query(
            $fetcher->createQuery('video_info')->select(
                'video_id',
                'channel_id',
                'title',
                'description',
                'category_id',
                'thumbnail',
                'published_at'
            )->where('video_id = :video_id'),
            ['video_id' => $videoId]
        );

        if (! $fetchedVideos) {
            return null;
        }

        $fetchedVideo = $fetchedVideos[0];

        return [
            'video_id' => $fetchedVideo['video_id'],
            'channel_id' => $fetchedVideo['channel_id'],
            'title' => $fetchedVideo['title'],
            'description' => $fetchedVideo['description'],
            'category_id' => $fetchedVideo['category_id'],
            'thumbnail' => $fetchedVideo['thumbnail'],
            'published_at' => $fetchedVideo['published_at']
        ];
    }
}
