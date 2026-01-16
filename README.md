# KASHITE API テスター

[![E2E Tests](https://github.com/laborrad/kashiteapp-tester/actions/workflows/test.yml/badge.svg)](https://github.com/laborrad/kashiteapp-tester/actions/workflows/test.yml)

KASHITE API（https://kashite.space）の動作確認・テスト用Webアプリケーション。

## 概要

このアプリケーションは、KASHITE APIの各エンドポイントをテストするためのインターフェースを提供します。

- 本番環境: https://tk2-233-26359.vs.sakura.ne.jp/kashiteapp/

## 機能

- 基本APIエンドポイントのテスト
- ニュース取得
- 検索条件の設定と検索URL生成
- 検索結果の表示
- カレンダーAPI
- カート投入機能

## 開発

### 必要な環境

- Docker & Docker Compose
- Node.js 20+ (テスト実行用、ローカルテストする場合のみ)

### ローカル起動

```bash
docker-compose up -d
```

http://localhost:8000 でアクセス可能

### E2Eテストの実行

#### Debian/Ubuntuでのセットアップ

```bash
# Node.jsとnpmのインストール（初回のみ）
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt-get install -y nodejs

# 依存関係のインストール
npm install

# Playwrightブラウザのインストール
npx playwright install --with-deps chromium
```

#### テスト実行

```bash
# テスト実行
npm test

# UIモードでテスト実行
npm run test:ui

# ヘッド付きブラウザでテスト実行
npm run test:headed

# テストレポート表示
npm run test:report
```

**注意**: ローカルでテストを実行しなくても、GitHubにpushすれば自動的にGitHub Actions上でテストが実行されます。

### CI/CD

GitHub Actionsを使用して、PR作成時・マージ時に自動的にE2Eテストが実行されます。

- `.github/workflows/test.yml`: テスト実行ワークフロー
- `tests/test_api.spec.js`: E2Eテストスイート

テストは以下の項目をカバーしています：

1. **UIテスト**
   - ページの正常な読み込み
   - 基本テストボタンの表示と動作
   - ニュース取得
   - フィルタ（会場タイプ、利用目的、エリア、料金レンジ）の取得
   - 検索URL生成
   - 条件クリア機能

2. **API直接テスト**
   - 各エンドポイントの疎通確認
   - レスポンスの形式チェック

## 構成

```
├── app/                     # Pythonアプリケーション（FastAPI）
│   ├── main.py             # APIプロキシサーバー
│   ├── static/             # フロントエンドファイル
│   │   ├── index.html      # メインHTML
│   │   ├── app.js          # JavaScriptロジック
│   │   └── style.css       # スタイル
│   └── requirements.txt    # Python依存関係
├── tests/                  # E2Eテストスイート
│   └── test_api.spec.js    # Playwrightテスト
├── .github/workflows/      # CI/CD設定
│   └── test.yml            # GitHub Actionsワークフロー
├── docker-compose.yml      # Docker構成
├── playwright.config.js    # Playwright設定
└── package.json            # Node.js依存関係
```

## ライセンス

MIT
