<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UniversalConverter</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= filemtime(__DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'app.css') ?>">
</head>
<body>
    <div class="background-decorations" aria-hidden="true">
        <div class="circle circle-1"></div><div class="circle circle-2"></div><div class="circle circle-3"></div>
    </div>
    <div class="app-container">
        <header class="app-header">
            <div class="logo"><i class="fa-solid fa-wand-magic-sparkles logo-icon"></i><h1>UniversalConverter</h1></div>
        </header>
        <main class="main-content">
            <div class="glass-card converter-card">
                <div class="mode-tabs">
                    <button type="button" class="mode-tab active" id="tabUpload"><i class="fa-solid fa-cloud-arrow-up"></i> Upload Ficheiro</button>
                    <button type="button" class="mode-tab" id="tabText"><i class="fa-solid fa-align-left"></i> Escrever Texto</button>
                    <button type="button" class="mode-tab" id="tabUrl"><i class="fa-solid fa-link"></i> Inserir URL</button>
                    <button type="button" class="mode-tab" id="tabMerge"><i class="fa-solid fa-object-group"></i> Juntar Ficheiros</button>
                </div>
                <div class="dropzone-wrapper">
                    <div id="dropzone" class="dropzone">
                        <input type="file" id="fileInput" class="file-input" accept="*/*">
                        <div class="dropzone-content"><div class="upload-icon-wrapper"><i class="fa-solid fa-cloud-arrow-up upload-icon"></i></div><h3>Arraste e solte o seu ficheiro aqui</h3><p>ou clique para procurar no computador</p></div>
                    </div>
                    <div id="textInputZone" class="text-input-zone hidden">
                        <textarea id="textContent" placeholder="Escreva ou cole o seu texto aqui para o converter..." class="text-input-area"></textarea>
                        <div class="text-actions">
                            <button type="button" id="useTextBtn" class="btn-use-text">Usar Texto</button>
                        </div>
                    </div>
                    <div id="urlInputZone" class="text-input-zone hidden">
                        <input type="url" id="urlContent" class="text-input-area" style="height: auto; padding: 15px;" placeholder="Cole aqui o URL do website (ex: https://pt.wikipedia.org/wiki/Portugal)" autocomplete="off">
                        <div style="text-align: right; margin-top: 15px;">
                            <button type="button" class="btn btn-primary" id="useUrlBtn"><i class="fa-solid fa-check"></i> Usar URL</button>
                        </div>
                    </div>
                    <div id="mergeInputZone" class="text-input-zone hidden">
                        <div class="dropzone merge-dropzone" id="mergeDropzone">
                            <input type="file" id="mergeFileInput" class="file-input" accept=".md" multiple>
                            <div class="dropzone-content">
                                <div class="upload-icon-wrapper"><i class="fa-solid fa-object-group upload-icon"></i></div>
                                <h3>Selecione ficheiros Markdown para juntar</h3>
                                <p>Arraste ou clique para selecionar ficheiros .md</p>
                            </div>
                        </div>
                        <ul id="mergeFileList" class="merge-file-list"></ul>
                        <div class="merge-actions">
                            <button type="button" class="merge-button" id="startMergeBtn" aria-label="Juntar todos os ficheiros Markdown" hidden><i class="fa-solid fa-object-group" aria-hidden="true"></i> <span>Juntar Tudo</span></button>
                        </div>
                    </div>
                    <div id="fileInfo" class="file-info-container hidden">
                        <div class="file-icon-box"><i class="fa-solid fa-file file-type-icon"></i></div>
                        <div class="file-details"><h4 id="fileName" class="file-name">ficheiro</h4><span id="fileSize" class="file-size">0 KB</span></div>
                        <button id="removeFileBtn" class="btn-icon" type="button" title="Remover ficheiro"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                </div>
                <div id="configSection" class="config-section hidden-opacity">
                    <h3 class="section-title"><i class="fa-solid fa-sliders"></i> Opções de conversão</h3>
                    <div class="form-group">
                        <label class="form-label">Escolha o formato de saída:</label>
                        <div class="search-format-container">
                            <div class="search-input-wrapper active">
                                <i class="fa-solid fa-magnifying-glass search-icon-inside"></i>
                                <input type="text" id="formatSearchInput" placeholder="Pesquisar formato compatível..." autocomplete="off">
                                <button type="button" id="clearSearchBtn" class="btn-clear-search hidden"><i class="fa-solid fa-xmark"></i></button>
                            </div>
                            <div id="formatDropdown" class="format-dropdown hidden"></div>
                        </div>
                    </div>
                    <div class="accordion">
                        <button type="button" class="accordion-trigger" id="accordionTrigger"><span>Opções avançadas de ajuste</span><i class="fa-solid fa-chevron-down accordion-icon"></i></button>
                        <div class="accordion-content" id="accordionContent"><div class="advanced-grid">
                            <div class="form-group"><label class="form-label">Redimensionar (largura × altura):</label><div class="resize-inputs"><input type="number" id="resizeWidth" placeholder="Largura (px)" min="1" max="16000"><span class="resize-separator">×</span><input type="number" id="resizeHeight" placeholder="Altura (px)" min="1" max="16000"></div><span class="input-tip">Deixe vazio para manter o tamanho original.</span></div>
                            <div class="form-group" id="qualityGroup"><label class="form-label">Qualidade da imagem: <span id="qualityVal">90%</span></label><input type="range" id="qualityRange" min="1" max="100" value="90" class="range-slider"></div>
                            <div class="form-group"><label class="form-label">Rodacionar:</label><select id="rotationSelect" class="form-select"><option value="0">Sem rotação</option><option value="90">90° horário</option><option value="180">180° invertido</option><option value="270">90° anti-horário</option></select></div>
                        </div></div>
                    </div>
                    <button type="button" id="convertBtn" class="btn-convert"><span>Iniciar conversão</span><i class="fa-solid fa-circle-play btn-icon-right"></i></button>
                </div>
                <div id="processingState" class="processing-state hidden"><div class="spinner-wrapper"><div class="spinner"></div><i class="fa-solid fa-gear spinning-gear"></i></div><h3>A processar ficheiro...</h3><p>O ImageMagick está a converter o ficheiro no seu computador.</p><div class="progress-bar-container"><div class="progress-bar" id="progressBar"></div></div></div>
                <div id="successState" class="success-state hidden"><div class="success-icon-wrapper"><i class="fa-solid fa-circle-check success-icon"></i></div><h3>Conversão concluída!</h3><p id="successText">O ficheiro convertido está pronto para descarregar.</p><div class="action-buttons-group"><a id="downloadBtn" class="btn-download" href="#" download><i class="fa-solid fa-download"></i> Descarregar ficheiro</a><button type="button" id="resetBtn" class="btn-reset"><i class="fa-solid fa-arrow-rotate-left"></i> Converter outro</button></div></div>
            </div>
            <div class="glass-card history-card"><div class="history-header"><h3><i class="fa-solid fa-history"></i> Histórico de conversões</h3><button id="clearHistoryBtn" class="btn-clear-history" type="button">Limpar histórico</button></div><div id="historyList" class="history-list"></div></div>
        </main>

    </div>
    <script src="assets/js/app.js?v=<?= time() ?>"></script>
</body>
</html>
