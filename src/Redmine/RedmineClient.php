<?php

namespace RedmineSummarizer\Redmine;

class RedmineClient
{
    private $url;
    private $apiKey;
    private $debug;
    private $insecure;

    public function __construct(string $url, string $apiKey, bool $debug = false, bool $insecure = false)
    {
        $this->url = rtrim($url, '/');
        $this->apiKey = $apiKey;
        $this->debug = $debug;
        $this->insecure = $insecure;
    }

    /**
     * デバッグモードを有効/無効にする
     */
    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
    }

    /**
     * 非セキュアモード（SSL検証無効）を設定
     */
    public function setInsecure(bool $insecure): void
    {
        $this->insecure = $insecure;
    }

    /**
     * 課題を作成
     * 
     * @param int $projectId プロジェクトID
     * @param string $subject 課題タイトル
     * @param string $description 課題説明
     * @return array 作成された課題情報
     */
    public function createIssue(int $projectId, string $subject, string $description): array
    {
        $data = [
            'issue' => [
                'project_id' => $projectId,
                'subject' => $subject,
                'description' => $description,
                'tracker_id' => 1
            ]
        ];

        return $this->makeRequest('POST', '/issues.json', $data);
    }

    /**
     * Wikiページを作成または更新
     * 
     * @param int $projectId プロジェクトID
     * @param string $title Wikiページタイトル
     * @param string $content Wikiページ内容
     * @param string $comments 変更コメント（更新時のみ）
     * @return array 作成/更新されたWikiページ情報
     */
    public function updateWikiPage(int $projectId, string $title, string $content, string $comments = ''): array
    {
        // プロジェクト情報を取得してidentifierを取得
        $project = $this->getProject($projectId);
        $projectIdentifier = $project['project']['identifier'];

        $data = [
            'wiki_page' => [
                'text' => $content,
                'comments' => $comments
            ]
        ];

        try {
            // ページが存在するか確認
            $this->makeRequest('GET', "/projects/{$projectIdentifier}/wiki/{$title}.json");
            
            if ($this->debug) {
                echo "Wiki ページが存在します。更新します。\n";
            }
            
            // 存在する場合は更新
            return $this->makeRequest('PUT', "/projects/{$projectIdentifier}/wiki/{$title}.json", $data);
        } catch (\Exception $e) {
            if ($this->debug) {
                echo "Wiki ページが存在しません。新規作成します。\n";
            }
            
            // 存在しない場合は新規作成
            return $this->makeRequest('PUT', "/projects/{$projectIdentifier}/wiki/{$title}.json", $data);
        }
    }

    /**
     * プロジェクト情報を取得
     * 
     * @param int $projectId プロジェクトID
     * @return array プロジェクト情報
     */
    public function getProject(int $projectId): array
    {
        return $this->makeRequest('GET', "/projects/{$projectId}.json");
    }

    /**
     * プロジェクト一覧を取得
     * 
     * @param int $limit 取得件数の上限（デフォルト: 100）
     * @param int $offset オフセット（デフォルト: 0）
     * @return array|null プロジェクト一覧（エラーの場合はnull）
     */
    public function getProjects(int $limit = 100, int $offset = 0): ?array
    {
        try {
            $allProjects = [];
            $currentOffset = $offset;
            
            do {
                $endpoint = "/projects.json?limit={$limit}&offset={$currentOffset}";
                $response = $this->makeRequest('GET', $endpoint);
                
                if (!isset($response['projects'])) {
                    if ($this->debug) {
                        echo "プロジェクト一覧の取得に失敗しました。レスポンス: " . json_encode($response) . "\n";
                    }
                    return null;
                }
                
                $projects = $response['projects'];
                $allProjects = array_merge($allProjects, $projects);
                
                // 次のページがあるかチェック
                $totalCount = $response['total_count'] ?? count($projects);
                $currentOffset += $limit;
                
                if ($this->debug) {
                    echo "取得済み: " . count($allProjects) . "/{$totalCount} プロジェクト\n";
                }
                
            } while (count($projects) === $limit && count($allProjects) < $totalCount);
            
            // IDでソート
            usort($allProjects, function($a, $b) {
                return $a['id'] <=> $b['id'];
            });
            
            if ($this->debug) {
                echo "プロジェクト一覧をIDでソートしました\n";
            }
            
            return $allProjects;
            
        } catch (\Exception $e) {
            if ($this->debug) {
                echo "プロジェクト一覧取得エラー: " . $e->getMessage() . "\n";
            }
            return null;
        }
    }

    /**
     * API接続テスト
     * 
     * @return array 接続テスト結果
     */
    public function testConnection(): array
    {
        try {
            $result = $this->makeRequest('GET', '/projects.json?limit=1');
            return [
                'success' => true,
                'message' => 'API接続成功',
                'data' => $result
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'API接続失敗: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }

    /**
     * URL疎通テスト（HTTPとHTTPSの両方で試す）
     * 
     * @return array テスト結果
     */
    public function testUrlAccess(): array
    {
        $results = [];
        
        // 現在のURLで接続テスト
        $results['current_url'] = $this->testSingleUrl($this->url);
        
        // HTTPSとHTTPを両方試す
        if (strpos($this->url, 'https://') === 0) {
            $httpUrl = str_replace('https://', 'http://', $this->url);
            $results['http_version'] = $this->testSingleUrl($httpUrl);
        } elseif (strpos($this->url, 'http://') === 0) {
            $httpsUrl = str_replace('http://', 'https://', $this->url);
            $results['https_version'] = $this->testSingleUrl($httpsUrl);
        }
        
        return $results;
    }

    /**
     * 単一URLの接続テスト
     */
    private function testSingleUrl(string $url): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url . '/projects.json?limit=1');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Redmine-API-Key: ' . $this->apiKey,
            'User-Agent: RedmineSummarizer/1.0'
        ]);

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        return [
            'url' => $url,
            'status_code' => $statusCode,
            'curl_error' => $curlError,
            'response_size' => strlen($response),
            'total_time' => $info['total_time'] ?? 0,
            'success' => empty($curlError) && $statusCode >= 200 && $statusCode < 300
        ];
    }

    /**
     * Redmine APIにリクエストを送信
     * 
     * @param string $method HTTPメソッド
     * @param string $endpoint エンドポイント
     * @param array|null $data 送信データ
     * @return array レスポンスデータ
     */
    protected function makeRequest(string $method, string $endpoint, array $data = null): array
    {
        $url = $this->url . $endpoint;
        
        if ($this->debug) {
            echo "=== Redmine API リクエスト ===\n";
            echo "URL: {$url}\n";
            echo "Method: {$method}\n";
            echo "API Key: " . substr($this->apiKey, 0, 8) . "...\n";
            echo "Insecure Mode: " . ($this->insecure ? 'Yes' : 'No') . "\n";
            if ($data) {
                echo "Data: " . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
            }
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'RedmineSummarizer/1.0');
        
        // SSL設定
        if ($this->insecure) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        }
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Redmine-API-Key: ' . $this->apiKey,
            'User-Agent: RedmineSummarizer/1.0'
        ]);

        switch (strtoupper($method)) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                if ($data) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                if ($data) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
                break;
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
        }

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlInfo = curl_getinfo($ch);
        curl_close($ch);

        if ($this->debug) {
            echo "=== Redmine API レスポンス ===\n";
            echo "Status Code: {$statusCode}\n";
            echo "Response: " . substr($response, 0, 500) . (strlen($response) > 500 ? "..." : "") . "\n";
            if ($curlError) {
                echo "cURL Error: {$curlError}\n";
            }
            echo "cURL Info: " . json_encode([
                'total_time' => $curlInfo['total_time'],
                'connect_time' => $curlInfo['connect_time'],
                'http_code' => $curlInfo['http_code'],
                'url' => $curlInfo['url'],
                'content_type' => $curlInfo['content_type']
            ], JSON_PRETTY_PRINT) . "\n";
            echo "=============================\n";
        }

        // cURLエラーの場合
        if ($curlError) {
            throw new \Exception("cURL エラー: {$curlError}");
        }

        // HTTPステータスコードが0の場合（接続できない）
        if ($statusCode === 0) {
            throw new \Exception("サーバーに接続できません。URL、ネットワーク接続、SSL証明書を確認してください。");
        }

        // HTTPエラーの場合
        if ($statusCode < 200 || $statusCode >= 300) {
            $errorMessage = "Redmine API エラー: ステータスコード {$statusCode}";
            
            // レスポンスがJSONの場合、エラーメッセージを抽出
            $responseData = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($responseData['errors'])) {
                $errorMessage .= " - " . implode(', ', $responseData['errors']);
            }
            
            $errorMessage .= ", レスポンス: {$response}";
            throw new \Exception($errorMessage);
        }

        // 空のレスポンスの場合（PUT/DELETEで正常）
        if (empty($response)) {
            return ['success' => true];
        }

        // JSONデコード
        $responseData = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("レスポンスのJSONパースエラー: " . json_last_error_msg() . ", レスポンス: {$response}");
        }

        return $responseData;
    }
}