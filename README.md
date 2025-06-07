# Redmine Activity Summarizer

RedmineのアクティビティデータをPostgreSQLから取得し、Google Gemini 2.5 AIを使用して要約した後、その要約をRedmineに投稿するPHPアプリケーションです。

## 機能

- PostgreSQLデータベースから直接Redmineのアクティビティを取得
- Gemini 2.5 AIを使用してアクティビティの要約を生成
- 生成された要約をRedmineの課題またはWikiページとして投稿

## 要件

- PHP 7.4以上
- Composer
- PostgreSQLデータベースへのアクセス
- Redmine APIキー
- Google Gemini APIキー

## インストール

```bash
# リポジトリをクローン
git clone https://your-repository-url.git
cd redmine-activity-summarizer

# 依存パッケージをインストール
composer install

# 環境設定ファイルをコピーして編集
cp .env.example .env
# .envファイルを編集して必要な設定を行ってください
```

## 使い方

```bash
# アクティビティを要約して投稿する
php bin/summarize.php
```

## ライセンス

MIT
