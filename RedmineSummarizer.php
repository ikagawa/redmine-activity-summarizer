
#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use RedmineSummarizer\Database\RedmineDatabase;
use RedmineSummarizer\AI\GeminiClient;
use RedmineSummarizer\Redmine\RedmineClient;
use RedmineSummarizer\SummarizerService;
use Dotenv\Dotenv;

// .envから環境変数を読み込む
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// .envファイルの値を優先したい場合のフォールバック
$geminiApiKey = $_ENV['GEMINI_API_KEY'] ?? null;

// .envファイルから直接読み取る（最後の手段）
if (empty($geminiApiKey)) {
    $envContent = file_get_contents(__DIR__ . '/../.env');
    if (preg_match('/^GEMINI_API_KEY=(.+)$/m', $envContent, $matches)) {
        $geminiApiKey = trim($matches[1]);
        $_ENV['GEMINI_API_KEY'] = $geminiApiKey;
    }
}

// コマンドライン引数の処理
$options = getopt('p:d:lch', ['project:', 'days:', 'list-temp', 'cleanup', 'help']);

if (isset($options['h']) || isset($options['help'])) {
    echo "使い方: php summarize.php [オプション]\n";
    echo "オプション:\n";
    echo "  -p, --project=ID    特定のプロジェクトIDのアクティビティのみを要約\n";
    echo "  -d, --days=NUM      要約する日数を指定（デフォルト: 環境変数のACTIVITY_DAYS）\n";
    echo "  -l, --list-temp     保存されている一時ファイルを一覧表示\n";
    echo "  -c, --cleanup       7日以上古い一時ファイルを削除\n";
    echo "  -h, --help          このヘルプメッセージを表示\n";
    exit(0);
}

// 設定を取得
$dbHost = $_ENV['DB_HOST'];
$dbPort = (int)$_ENV['DB_PORT'];
$dbName = $_ENV['DB_NAME'];
$dbUser = $_ENV['DB_USER'];
$dbPassword = $_ENV['DB_PASSWORD'];

$redmineUrl = $_ENV['REDMINE_URL'];
$redmineApiKey = $_ENV['REDMINE_API_KEY'];

$geminiApiKey = $_ENV['GEMINI_API_KEY'];

$activityDays = (int)($_ENV['ACTIVITY_DAYS'] ?? 7);
$projectId = (int)($_ENV['PROJECT_ID'] ?? 1);

// コマンドライン引数で上書き
if (isset($options['d']) || isset($options['days'])) {
    $activityDays = (int)(isset($options['d']) ? $options['d'] : $options['days']);
}

// サービスを初期化
try {
    $database = new RedmineDatabase($dbHost, $dbPort, $dbName, $dbUser, $dbPassword);
    $gemini = new GeminiClient($geminiApiKey);
    $redmine = new RedmineClient($redmineUrl, $redmineApiKey);

    $summarizer = new SummarizerService($database, $gemini, $redmine, $activityDays, $projectId);

    // 一時ファイル一覧表示
    if (isset($options['l']) || isset($options['list-temp'])) {
        $tempFiles = $summarizer->listTempFiles();
        if (empty($tempFiles)) {
            echo "一時ファイルはありません。\n";
        } else {
            echo "保存されている一時ファイル:\n";
            foreach ($tempFiles as $file) {
                $date = date('Y-m-d H:i:s', filemtime($file));
                $size = round(filesize($file) / 1024, 2);
                echo "  {$file} ({$size}KB, {$date})\n";
            }
        }
        exit(0);
    }

    // 古い一時ファイルのクリーンアップ
    if (isset($options['c']) || isset($options['cleanup'])) {
        $summarizer->cleanupOldTempFiles();
        exit(0);
    }

    // 特定のプロジェクトのみを処理する場合
    if (isset($options['p']) || isset($options['project'])) {
        $targetProjectId = (int)(isset($options['p']) ? $options['p'] : $options['project']);
        $summarizer->runForProject($targetProjectId);
    } else {
        // 全体のアクティビティを処理
        $summarizer->run();
    }

    echo "処理が完了しました。\n";
    exit(0);

} catch (Exception $e) {
    echo "エラーが発生しました: {$e->getMessage()}\n";
    exit(1);
}