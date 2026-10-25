<?php

// 静态页 TDK 本地化模板（嵌套结构，与 config/site.php 对齐；Seo::page 回退链：后台设置 → 语言包 → 配置）

return [
    'home' => [
        'title' => 'Produttore e Fornitore All\'ingrosso | {site}',
        'description' => 'Approvvigionamento B2B diretto dalla fabbrica: supporto OEM/ODM, controllo qualità AQL e spedizioni mondiali. Preventivo in 24 ore.',
    ],
    'products' => [
        'index' => [
            'title' => 'Prodotti all\'ingrosso dalla Cina | {site}',
            'description' => 'Sfoglia il catalogo: prezzi all\'ingrosso di fabbrica, qualità verificata, MOQ flessibile e supporto OEM/ODM.',
        ],
    ],
    'blog' => [
        'index' => [
            'title' => 'Guide di Approvvigionamento | {site}',
            'description' => 'Guide pratiche e approfondimenti settoriali del nostro team export per importare dalla Cina con fiducia.',
        ],
    ],
    'services' => [
        'index' => [
            'title' => 'Servizi OEM, ODM, QC e Logistica | {site}',
            'description' => 'Servizio completo di supply chain: ingegneria OEM/ODM, controllo qualità AQL, logistica globale e sourcing personalizzato — dalla richiesta alla consegna.',
        ],
    ],
    'contact' => [
        'title' => 'Contatti — Preventivo in 24h | {site}',
        'description' => 'Invia la tua richiesta: preventivi, campioni e collaborazione OEM/ODM. Risposta reale entro 24 ore lavorative.',
    ],
    'about' => [
        'title' => 'Chi Siamo — Oltre 10 Anni di Export | {site}',
        'description' => 'Il team dietro {site}: rete di fabbriche, controllo qualità AQL e oltre 10 anni al servizio di acquirenti B2B.',
    ],
    'industries' => [
        'index' => [
            'title' => 'Settori che Serviamo — Sourcing dalla Cina | {site}',
            'description' => 'Fornitura diretta dalla fabbrica in 18 settori: edilizia, macchinari, medico, energia e altri. Supporto OEM/ODM, controllo AQL e spedizioni mondiali.',
        ],
    ],
    'privacy' => [
        'title' => 'Informativa sulla Privacy | {site}',
        'description' => 'Come raccogliamo, usiamo e proteggiamo i tuoi dati personali — diritti GDPR e contatti.',
    ],
    'terms' => [
        'title' => 'Termini di Servizio | {site}',
        'description' => 'Come funzionano richieste, preventivi, ordini, pagamento e reclami di qualità.',
    ],
];
