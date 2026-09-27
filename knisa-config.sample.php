<?php
/*
 * knisa の設定の見本。knisa-config.php という名前で写して、自社の値に書き換えてください。
 * 書かなかった項目は既定値（社名なし・ロゴなし・計測なし）のままです。
 * 入力された取引はブラウザの中だけで計算し、この設定に関係なくサーバーへは送りません。
 */
return [
    'site_url'      => 'https://example.co.jp/nisa/knisa.php',   // この画面の正式なURL
    'brand_name'    => '〇〇税理士事務所',
    'brand_url'     => 'https://example.co.jp/',
    'logo_url'      => 'https://example.co.jp/images/logo.png',   // 正方形の画像
    'accent'        => '#1f6f9f',                                  // 見出し・ボタンの色（#rrggbb）
    'og_image'      => '',                                         // SNSで共有したときの画像（1200x630）
    'mascot_url'    => '',
    'contact_url'   => 'https://example.co.jp/contact/',
    'contact_label' => 'NISAの相談を予約する',
    'contact_text'  => '<b>枠の使い方や取り崩しの順番は、ご相談ください。</b>',
    'header_links'  => [['事務所のご案内', 'https://example.co.jp/']],
    'footer_extra'  => '〇〇税理士事務所',
    // 計測タグを入れる場合（例: Google Analytics）。不要なら空のまま
    'head_extra'    => '',
    'body_extra'    => '',
    // 紹介動画を題の下に出す場合（mp4 のURL・秒数）。不要なら空のまま
    'pv_url'        => '',
    'pv_poster'     => '',
    'pv_seconds'    => 0,
    // 構造化データの発行者（任意）
    'org_ld'        => null,
];
