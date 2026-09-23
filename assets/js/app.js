document.addEventListener('DOMContentLoaded', () => {
    const dropzone = document.getElementById('dropzone');
    const fileInput = document.getElementById('fileInput');
    const fileInfo = document.getElementById('fileInfo');
    const fileName = document.getElementById('fileName');
    const fileSize = document.getElementById('fileSize');
    const removeFileBtn = document.getElementById('removeFileBtn');
    const tabUpload = document.getElementById('tabUpload');
    const tabText = document.getElementById('tabText');
    const dropzoneArea = document.getElementById('dropzone');
    const textInputZone = document.getElementById('textInputZone');
    const textContent = document.getElementById('textContent');
    const useTextBtn = document.getElementById('useTextBtn');
    const tabUrl = document.getElementById('tabUrl');
    const urlInputZone = document.getElementById('urlInputZone');
    const urlContent = document.getElementById('urlContent');
    const useUrlBtn = document.getElementById('useUrlBtn');
    const tabMerge = document.getElementById('tabMerge');
    const modeTabs = document.querySelector('.mode-tabs');
    const mergeInputZone = document.getElementById('mergeInputZone');
    const mergeFileInput = document.getElementById('mergeFileInput');
    const mergeDropzone = document.getElementById('mergeDropzone');
    const mergeFileList = document.getElementById('mergeFileList');
    const startMergeBtn = document.getElementById('startMergeBtn');
    let filesToMerge = [];

    function resetTabs() {
        tabUpload.classList.remove('active');
        tabText.classList.remove('active');
        tabUrl.classList.remove('active');
        dropzoneArea.classList.add('hidden');
        textInputZone.classList.add('hidden');
        urlInputZone.classList.add('hidden');
        tabMerge.classList.remove('active');
        mergeInputZone.classList.add('hidden');
    }

    // Tab switching
    tabUpload.addEventListener('click', () => {
        resetTabs();
        tabUpload.classList.add('active');
        dropzoneArea.classList.remove('hidden');
    });
    tabText.addEventListener('click', () => {
        resetTabs();
        tabText.classList.add('active');
        textInputZone.classList.remove('hidden');
        textContent.focus();
    });
    tabUrl.addEventListener('click', () => {
        resetTabs();
        tabUrl.classList.add('active');
        urlInputZone.classList.remove('hidden');
        urlContent.focus();
    });

    tabMerge.addEventListener('click', () => {
        resetTabs();
        tabMerge.classList.add('active');
        mergeInputZone.classList.remove('hidden');
    });

    ['dragenter', 'dragover'].forEach(eventName => mergeDropzone.addEventListener(eventName, e => {
        e.preventDefault(); mergeDropzone.style.borderColor = 'white';
    }));
    ['dragleave', 'drop'].forEach(eventName => mergeDropzone.addEventListener(eventName, e => {
        e.preventDefault(); mergeDropzone.style.borderColor = 'rgba(255, 255, 255, 0.4)';
    }));

    mergeDropzone.addEventListener('drop', e => {
        handleMergeFiles(e.dataTransfer.files);
    });
    mergeFileInput.addEventListener('change', () => {
        handleMergeFiles(mergeFileInput.files);
        mergeFileInput.value = '';
    });

    function handleMergeFiles(files) {
        const markdownFiles = Array.from(files).filter((file) => /\.md$/i.test(file.name));
        if (markdownFiles.length !== files.length) {
            window.alert('A ferramenta de união aceita apenas ficheiros Markdown com extensão .md.');
        }
        for (const file of markdownFiles) {
            filesToMerge.push(file);
        }
        renderMergeList();
    }

    function renderMergeList() {
        mergeFileList.innerHTML = '';
        filesToMerge.forEach((f, index) => {
            const li = document.createElement('li');
            li.className = 'merge-file-item';

            const span = document.createElement('span');
            span.className = 'merge-file-name';
            span.textContent = f.name;

            const del = document.createElement('i');
            del.className = 'fa-solid fa-xmark merge-file-remove';
            del.onclick = () => {
                filesToMerge.splice(index, 1);
                renderMergeList();
            };

            li.appendChild(span);
            li.appendChild(del);
            mergeFileList.appendChild(li);
        });
        startMergeBtn.hidden = filesToMerge.length < 2;
    }

    startMergeBtn.addEventListener('click', async () => {
        if (filesToMerge.length < 2) return;

        startMergeBtn.disabled = true;
        startMergeBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> <span>A Juntar...</span>';

        try {
            let mergedText = '';
            for (const f of filesToMerge) {
                const text = await f.text();
                mergedText += text + '\n\n';
            }

            const blob = new Blob([mergedText], { type: 'text/markdown;charset=utf-8' });
            const mergedFile = new File([blob], 'ficheiros_juntos.md', { type: 'text/markdown;charset=utf-8' });

            filesToMerge = [];
            renderMergeList();

            resetTabs();
            tabUpload.classList.add('active');
            dropzoneArea.classList.remove('hidden');

            handleFile(mergedFile);
        } catch (e) {
            alert('Erro ao juntar ficheiros: ' + e.message);
        } finally {
            startMergeBtn.disabled = false;
            startMergeBtn.innerHTML = '<i class="fa-solid fa-object-group" aria-hidden="true"></i> <span>Juntar Tudo</span>';
        }
    });

    useUrlBtn.addEventListener('click', () => {
        const url = urlContent.value.trim();
        if (!url) { alert('Insira um URL primeiro.'); return; }
        if (!url.startsWith('http://') && !url.startsWith('https://')) { alert('O URL deve começar com http:// ou https://'); return; }
        const blob = new Blob([url], { type: 'text/plain' });
        const urlFile = new File([blob], 'website.url', { type: 'text/plain' });
        resetTabs();
        tabUpload.classList.add('active');
        dropzoneArea.classList.remove('hidden');
        handleFile(urlFile);
    });

    useTextBtn.addEventListener('click', () => {
        const text = textContent.value.trim();
        if (!text) { alert('Escreva ou cole algum texto primeiro.'); return; }
        // Create a blob file from text
        const blob = new Blob([text], { type: 'text/plain' });
        const textFile = new File([blob], 'texto.txt', { type: 'text/plain' });
        
        // Switch back to "Ficheiro" tab so we can see the info
        tabUpload.classList.add('active'); tabText.classList.remove('active');
        dropzoneArea.classList.remove('hidden'); textInputZone.classList.add('hidden');
        
        handleFile(textFile);
    });

    const configSection = document.getElementById('configSection');
    const formatButtons = document.querySelectorAll('.format-btn');
    const formatSearchInput = document.getElementById('formatSearchInput');
    const clearSearchBtn = document.getElementById('clearSearchBtn');
    const formatDropdown = document.getElementById('formatDropdown');
    const searchInputWrapper = formatSearchInput.closest('.search-input-wrapper');
    const accordionTrigger = document.getElementById('accordionTrigger');
    const accordion = accordionTrigger.closest('.accordion');
    const accordionContent = document.getElementById('accordionContent');
    const resizeWidth = document.getElementById('resizeWidth');
    const resizeHeight = document.getElementById('resizeHeight');
    const qualityGroup = document.getElementById('qualityGroup');
    const qualityRange = document.getElementById('qualityRange');
    const qualityVal = document.getElementById('qualityVal');
    const rotationSelect = document.getElementById('rotationSelect');
    const convertBtn = document.getElementById('convertBtn');
    const processingState = document.getElementById('processingState');
    const progressBar = document.getElementById('progressBar');
    const successState = document.getElementById('successState');
    const successText = document.getElementById('successText');
    const downloadBtn = document.getElementById('downloadBtn');
    const resetBtn = document.getElementById('resetBtn');
    const historyList = document.getElementById('historyList');
    const clearHistoryBtn = document.getElementById('clearHistoryBtn');

    function setConversionState(state) {
        const isInitial = state === 'idle' || state === 'error';
        const isProcessing = state === 'processing';
        const isSuccess = state === 'success';

        modeTabs.classList.toggle('hidden', !isInitial);
        dropzoneArea.classList.toggle('hidden', !isInitial || selectedFile !== null);
        fileInfo.classList.toggle('hidden', !isInitial || selectedFile === null);
        configSection.classList.toggle('hidden', !isInitial || selectedFile === null);
        configSection.classList.toggle('show-config', isInitial && selectedFile !== null);
        processingState.classList.toggle('hidden', !isProcessing);
        successState.classList.toggle('hidden', !isSuccess);
    }

    const maxUploadBytes = 100 * 1024 * 1024;
    const historyKey = 'universal_converter_history';
    let selectedFile = null;
    let selectedFormat = 'png';
    let selectedMediaKind = 'image';
    let allFormats = [];
    let currentDownloadUrl = '';
    let conversionHistory = readHistory();
    renderHistory();
    const COMPATIBLE_FORMATS = {
        image: ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg', 'bmp', 'ico', 'tiff', 'tif', 'avif', 'heic', 'psd', 'pdf', 'eps', 'tga', 'wbmp'],
        document: ['md', 'txt', 'pdf', 'html', 'ipynb', 'doc', 'docx'],
        video: ['mp4', 'webm', 'mkv', 'avi', 'mov', 'flv', 'wmv', 'm4v', '3gp', 'gif', 'mp3', 'wav', 'm4a', 'aac', 'zip'],
        audio: ['mp3', 'wav', 'm4a', 'aac', 'flac', 'ogg', 'opus', 'wma']
    };

    function getAvailableFormats() {
        const allowed = [...(COMPATIBLE_FORMATS[selectedMediaKind] || COMPATIBLE_FORMATS.image)];
        const extension = selectedFile?.name.split('.').pop().toLowerCase();
        if (extension === 'pdf') {
            allowed.push('md', 'txt');
        }
        if (allFormats.length > 0) {
            return allFormats.filter((fmt) => allowed.includes(fmt));
        }
        return [...new Set(allowed)];
    }

    function updatePlaceholder() {
        const list = getAvailableFormats();
        const examples = list.slice(0, 4).join(', ');
        formatSearchInput.placeholder = `Pesquisar formato compatível (ex.: ${examples})...`;
    }

    async function loadFormats() {
        try {
            const response = await fetch('api/formats.php', { cache: 'no-store' });
            if (!response.ok) return;
            const formats = await response.json();
            if (Array.isArray(formats)) {
                allFormats = formats.filter((format) => typeof format === 'string');
                updatePlaceholder();
            }
        } catch (_) { }
    }

    ['dragenter', 'dragover'].forEach((eventName) => dropzone.addEventListener(eventName, (event) => {
        event.preventDefault(); dropzone.classList.add('dragover');
    }));
    ['dragleave', 'drop'].forEach((eventName) => dropzone.addEventListener(eventName, (event) => {
        event.preventDefault(); dropzone.classList.remove('dragover');
    }));
    dropzone.addEventListener('drop', (event) => {
        const [file] = event.dataTransfer.files; if (file) handleFile(file);
    });
    fileInput.addEventListener('change', () => { if (fileInput.files[0]) handleFile(fileInput.files[0]); });
    removeFileBtn.addEventListener('click', (event) => { event.stopPropagation(); resetToUpload(); });

    function handleFile(file) {
        if (file.size > maxUploadBytes) { window.alert('O ficheiro excede o limite de 100 MB.'); return; }
        selectedFile = file;
        fileName.textContent = file.name;
        fileSize.textContent = formatBytes(file.size);
        dropzone.classList.add('hidden'); fileInfo.classList.remove('hidden');
        configSection.classList.remove('hidden-opacity'); configSection.classList.add('show-config');
        const icon = fileInfo.querySelector('.file-type-icon');
        const extension = file.name.split('.').pop().toLowerCase();
        icon.style.color = '';

        if (file.type.startsWith('image/')) {
            selectedMediaKind = 'image'; icon.className = 'fa-solid fa-file-image file-type-icon'; updateSelectedFormat('png');
        } else if (extension === 'pdf') {
            selectedMediaKind = 'document'; icon.className = 'fa-solid fa-file-pdf file-type-icon'; icon.style.color = '#e01b22'; updateSelectedFormat('md');
        } else if (extension === 'url') {
            selectedMediaKind = 'document'; icon.className = 'fa-solid fa-link file-type-icon'; icon.style.color = '#1b73e8'; updateSelectedFormat('md');
        } else if (['doc', 'docx', 'docm', 'ppt', 'pptx', 'pptm', 'pps', 'ppsx', 'ppsm', 'pot', 'xls', 'xlsx', 'xlsm', 'xlsb', 'odt', 'ods', 'odp', 'rtf', 'epub', 'csv', 'txt', 'py', 'json', 'md', 'html', 'log'].includes(extension)) {
            selectedMediaKind = 'document'; icon.className = 'fa-solid fa-file-lines file-type-icon'; icon.style.color = '#2b579a'; updateSelectedFormat('md');
        } else if (['mp4', 'm4v', 'mov', 'mkv', 'avi', 'webm', 'wmv', 'flv', 'mpeg', 'mpg', '3gp'].includes(extension)) {
            selectedMediaKind = 'video'; icon.className = 'fa-solid fa-file-video file-type-icon'; updateSelectedFormat('mp4');
        } else if (['mp3', 'm4a', 'aac', 'wav', 'flac', 'ogg', 'opus', 'wma'].includes(extension)) {
            selectedMediaKind = 'audio'; icon.className = 'fa-solid fa-file-audio file-type-icon'; updateSelectedFormat('mp3');
        } else {
            selectedMediaKind = 'image'; icon.className = 'fa-solid fa-file file-type-icon'; updateSelectedFormat('png');
        }
        updatePlaceholder();
        updateAdvancedControls();
    }

    function updateSelectedFormat(format) {
        selectedFormat = String(format).toLowerCase().trim();
        formatSearchInput.value = selectedFormat.toUpperCase();
        clearSearchBtn.classList.remove('hidden');
        searchInputWrapper.classList.add('active');
        updateAdvancedControls();
    }

    function updateAdvancedControls() {
        const resizeGroup = resizeWidth.closest('.form-group');
        const rotationGroup = rotationSelect.closest('.form-group');
        const qualityLabel = qualityGroup.querySelector('.form-label');
        const isVideo = selectedMediaKind === 'video';
        const isAudio = selectedMediaKind === 'audio';
        const isDocument = selectedMediaKind === 'document';

        accordion.style.display = (isAudio || isDocument) ? 'none' : '';
        if (isAudio || isDocument) { accordion.classList.remove('open'); accordionContent.style.maxHeight = null; }
        if (isDocument) return;

        resizeGroup.classList.remove('hidden'); rotationGroup.classList.remove('hidden');
        qualityGroup.classList.toggle('hidden', !(isVideo || ['jpg', 'jpeg', 'webp', 'tiff', 'tif', 'avif', 'heic'].includes(selectedFormat)));
        if (qualityLabel) qualityLabel.innerHTML = `${isVideo ? 'Qualidade do vídeo' : 'Qualidade da imagem'}: <span id="qualityVal">${qualityRange.value}%</span>`;
    }

    formatSearchInput.addEventListener('input', () => {
        const query = formatSearchInput.value.trim().toLowerCase();
        const available = getAvailableFormats();
        if (query === '') {
            renderDropdown(available);
            return;
        }
        clearSearchBtn.classList.remove('hidden');
        renderDropdown(available.filter((format) => format.includes(query)));
    });

    formatSearchInput.addEventListener('focus', () => {
        const query = formatSearchInput.value.trim().toLowerCase();
        const available = getAvailableFormats();
        renderDropdown(query ? available.filter((format) => format.includes(query)) : available);
    });

    formatSearchInput.addEventListener('click', () => {
        const query = formatSearchInput.value.trim().toLowerCase();
        const available = getAvailableFormats();
        renderDropdown(query ? available.filter((format) => format.includes(query)) : available);
    });

    clearSearchBtn.addEventListener('click', () => {
        formatSearchInput.value = '';
        renderDropdown(getAvailableFormats());
        formatSearchInput.focus();
    });

    document.addEventListener('click', (event) => {
        if (!formatSearchInput.contains(event.target) && !formatDropdown.contains(event.target)) {
            hideDropdown();
        }
    });

    function renderDropdown(items) {
        formatDropdown.replaceChildren();
        if (items.length === 0) {
            const empty = document.createElement('div'); empty.className = 'format-dropdown-no-results'; empty.textContent = 'Nenhum formato compatível encontrado'; formatDropdown.append(empty);
        } else {
            items.slice(0, 30).forEach((format) => {
                const item = document.createElement('button'); item.type = 'button'; item.className = 'format-dropdown-item'; item.textContent = format.toUpperCase();
                item.addEventListener('click', () => { updateSelectedFormat(format); hideDropdown(); }); formatDropdown.append(item);
            });
        }
        formatDropdown.classList.remove('hidden');
    }
    function hideDropdown() { formatDropdown.classList.add('hidden'); }

    qualityRange.addEventListener('input', () => { const value = document.getElementById('qualityVal'); if (value) value.textContent = `${qualityRange.value}%`; });
    accordionTrigger.addEventListener('click', () => {
        const opening = !accordion.classList.contains('open'); accordion.classList.toggle('open', opening); accordionContent.style.maxHeight = opening ? `${accordionContent.scrollHeight}px` : null;
    });

    convertBtn.addEventListener('click', async () => {
        if (!selectedFile) return;
        setConversionState('processing');
        convertBtn.disabled = true;
        progressBar.style.width = '0%'; let progress = 0;
        const timer = window.setInterval(() => { if (progress < 90) { progress += (90 - progress) * 0.12; progressBar.style.width = `${progress}%`; } }, 150);
        try {
            const extension = selectedFile.name.split('.').pop().toLowerCase();
            const anydocExts = ['doc', 'docx', 'docm', 'ppt', 'pptx', 'pptm', 'pps', 'ppsx', 'ppsm', 'pot', 'xls', 'xlsx', 'xlsm', 'xlsb', 'odt', 'ods', 'odp', 'rtf', 'epub', 'csv'];
            let convertedFileName = '';
            let blob = null;

            if (selectedFormat === 'md' && anydocExts.includes(extension)) {
                const { default: initAnydoc, formatFromExtension, toMarkdownBytes } = await import('./assets/wasm/anydoc_wasm.js');
                await initAnydoc();
                const bytes = new Uint8Array(await selectedFile.arrayBuffer());
                const format = formatFromExtension(extension) || undefined;
                let markdown = toMarkdownBytes(bytes, format);
                if (typeof markdown !== 'string' || markdown.trim() === '') throw new Error('Não foi possível obter conteúdo do documento.');
                markdown = enhanceToRealMarkdown(markdown);
                blob = new Blob([markdown], { type: 'text/markdown;charset=utf-8' });
                convertedFileName = selectedFile.name.replace(/\.[^.]+$/, '').trim().replace(/[\\/:*?"<>|]+/g, '-').slice(0, 180) + '.md';
                window.clearInterval(timer); progressBar.style.width = '100%';
            } else {
                const formData = new FormData();
                formData.append('file', selectedFile); formData.append('format', selectedFormat);
                if (resizeWidth.value) formData.append('width', resizeWidth.value);
                if (resizeHeight.value) formData.append('height', resizeHeight.value);
                if (!qualityGroup.classList.contains('hidden')) formData.append('quality', qualityRange.value);
                if (rotationSelect.value !== '0') formData.append('rotation', rotationSelect.value);
                const response = await fetch('api/convert.php', { method: 'POST', body: formData });
                if (!response.ok) throw new Error(await serverError(response));
                blob = await response.blob();
                if (blob.size === 0) throw new Error('O servidor não devolveu nenhum ficheiro convertido.');
                window.clearInterval(timer); progressBar.style.width = '100%';
                convertedFileName = filenameFromHeader(response.headers.get('content-disposition')) || generatedName(selectedFile.name, selectedFormat);
            }

            releaseDownloadUrl(); currentDownloadUrl = URL.createObjectURL(blob); downloadBtn.href = currentDownloadUrl; downloadBtn.download = convertedFileName;
            successText.textContent = `O ficheiro "${convertedFileName}" está pronto para descarregar.`;
            window.setTimeout(() => {
                downloadBtn.click();
                setConversionState('success');
                addHistoryItem(selectedFile.name, convertedFileName, selectedFormat);
            }, 450);
        } catch (error) {
            setConversionState('error');
            const message = error instanceof TypeError && error.message === 'Failed to fetch'
                ? 'Não foi possível contactar o servidor local. Confirme se o UniversalConverter está em execução e tente novamente.'
                : error instanceof Error ? error.message : 'erro desconhecido.';
            window.alert(`Erro na conversão: ${message}`);
        } finally { window.clearInterval(timer); convertBtn.disabled = false; }
    });

    resetBtn.addEventListener('click', () => { resetToUpload(); });
    function resetToUpload() {
        releaseDownloadUrl();
        selectedFile = null;
        selectedMediaKind = 'image';
        fileInput.value = '';
        modeTabs.classList.remove('hidden');
        successState.classList.add('hidden');
        processingState.classList.add('hidden');
        fileInfo.classList.add('hidden');
        dropzone.classList.remove('hidden');
        configSection.classList.remove('hidden');
        configSection.classList.remove('show-config');
        configSection.classList.add('hidden-opacity');
        tabUpload.click();
        accordion.classList.remove('open'); accordionContent.style.maxHeight = null; resizeWidth.value = ''; resizeHeight.value = ''; qualityRange.value = '90'; const value = document.getElementById('qualityVal'); if (value) value.textContent = '90%'; rotationSelect.value = '0'; updateSelectedFormat('png');
    }

    function addHistoryItem(originalName, convertedName, format) {
        conversionHistory.unshift({ id: Date.now(), originalName, convertedName, format, timestamp: new Date().toLocaleTimeString('pt-PT', { hour: '2-digit', minute: '2-digit' }) });
        conversionHistory = conversionHistory.slice(0, 5); localStorage.setItem(historyKey, JSON.stringify(conversionHistory)); renderHistory();
    }
    function readHistory() { try { const saved = JSON.parse(localStorage.getItem(historyKey) || '[]'); return Array.isArray(saved) ? saved.slice(0, 5) : []; } catch (_) { return []; } }
    function renderHistory() {
        historyList.replaceChildren();
        if (conversionHistory.length === 0) { const empty = document.createElement('div'); empty.className = 'history-empty'; empty.innerHTML = '<i class="fa-regular fa-folder-open empty-icon" aria-hidden="true"></i><p>Nenhuma conversão realizada ainda.</p>'; historyList.append(empty); return; }
        conversionHistory.forEach((item) => { const row = document.createElement('div'); row.className = 'history-item'; const info = document.createElement('div'); info.className = 'history-info'; const icon = document.createElement('i'); icon.className = 'fa-solid fa-circle-check history-file-icon'; const details = document.createElement('div'); details.className = 'history-details'; const name = document.createElement('span'); name.className = 'history-name'; name.title = item.convertedName; name.textContent = item.convertedName; const meta = document.createElement('span'); meta.className = 'history-meta'; meta.textContent = `Convertido para ${String(item.format).toUpperCase()} às ${item.timestamp}`; details.append(name, meta); info.append(icon, details); const actions = document.createElement('div'); actions.className = 'history-actions'; const badge = document.createElement('span'); badge.className = 'badge'; badge.style.cssText = 'margin-top:0;font-size:10px;'; badge.textContent = String(item.format).toUpperCase(); actions.append(badge); row.append(info, actions); historyList.append(row); });
    }
    clearHistoryBtn.addEventListener('click', () => { conversionHistory = []; localStorage.removeItem(historyKey); renderHistory(); });
    async function serverError(response) { try { const data = await response.json(); return typeof data.error === 'string' ? data.error : 'Falha desconhecida na conversão.'; } catch (_) { return `O servidor devolveu o erro ${response.status}.`; } }
    function filenameFromHeader(header) { if (!header) return ''; const encoded = header.match(/filename\*=UTF-8''([^;]+)/i); if (encoded?.[1]) { try { return decodeURIComponent(encoded[1]); } catch (_) { return encoded[1]; } } return ''; }
    function generatedName(name, format) { const position = name.lastIndexOf('.'); return `${position > 0 ? name.slice(0, position) : name}_convertido.${format}`; }
    function releaseDownloadUrl() { if (currentDownloadUrl) { URL.revokeObjectURL(currentDownloadUrl); currentDownloadUrl = ''; } }
    function formatBytes(bytes) { if (bytes === 0) return '0 Bytes'; const units = ['Bytes', 'KB', 'MB', 'GB']; const index = Math.floor(Math.log(bytes) / Math.log(1024)); return `${(bytes / Math.pow(1024, index)).toFixed(index ? 2 : 0)} ${units[index]}`; }

    function enhanceToRealMarkdown(text) {
        if (!text || typeof text !== 'string') return text;

        let rawLines = text.split(/\r?\n/).map(l => l.trim());
        while (rawLines.length > 0 && rawLines[0] === '') rawLines.shift();

        rawLines = rawLines.filter(l => {
            if (/^\*?🕐?\s*\d{1,2}\s+de\s+[a-z]+\.?\s+de\s+\d{4}/i.test(l)) return false;
            if (/^\d{1,2}:\d{2}(:\d{2})?/i.test(l)) return false;
            if (/\[stay in caveman/i.test(l)) return false;
            if (/^Turno\s+\d+/i.test(l)) return false;
            if (/^(Usuário|Gemini|🔗 Link original|📅 Exportado em|Gerado por)/i.test(l)) return false;
            if (/como\s+descreverias|como\s+descreveria|super\s+detalhada|forma\s+super|como\s+é\s+que\s+é/i.test(l)) return false;
            return true;
        });

        const result = [];
        let inSection = false;

        for (let i = 0; i < rawLines.length; i++) {
            let line = rawLines[i];

            if (line === '') {
                result.push('');
                continue;
            }

            if (/^[-*_]{3,}$/.test(line)) {
                result.push('---');
                continue;
            }

            // Normalize Windows backslashes in paths
            line = line.replace(/\\/g, '/');
            line = line.replace(/`/g, '');

            const startsWithEmoji = /^[\u{1F300}-\u{1F9FF}\u{2600}-\u{26FF}\u{2700}-\u{27BF}\u{1F600}-\u{1F64F}\u{1F680}-\u{1F6FF}]/u.test(line);
            if (startsWithEmoji) {
                line = line.replace(/^#+\s*/, '');
                result.push('## ' + line);
                inSection = false;
                continue;
            }

            if (/^(Colega \d+|Projeto \d+|Fase \d+|Etapa \d+|Modulo \d+|Relatório|Veredito|Conclusão)/i.test(line) && !line.includes(':')) {
                line = line.replace(/^#+\s*/, '');
                result.push('## ' + line);
                inSection = false;
                continue;
            }

            if (/^[A-Za-zÀ-ÿ0-9\s/()_-]{2,30}:$/i.test(line) || /^Resumo/i.test(line)) {
                line = line.replace(/^#+\s*/, '');
                if (/^Resumo/i.test(line)) {
                    line = `**${line.replace(/\*\*/g, '')}**`;
                } else {
                    result.push('### ' + line);
                    inSection = true;
                    continue;
                }
            }

            if (line.includes('?') && !line.startsWith('-') && !line.startsWith('*') && !line.startsWith('#')) {
                const qIndex = line.indexOf('?');
                const questionPart = line.slice(0, qIndex + 1).trim();
                const answerPart = line.slice(qIndex + 1).trim();
                line = `- **${questionPart}** ${answerPart}`.trim();
            } else if (line.includes(': ') && !line.startsWith('#') && !line.startsWith('-') && !line.startsWith('*') && !line.startsWith('>')) {
                const cIndex = line.indexOf(': ');
                const labelPart = line.slice(0, cIndex).trim();
                const valuePart = line.slice(cIndex + 2).trim();
                if (labelPart.length < 40 && !labelPart.includes('\n')) {
                    if (inSection) {
                        line = `- **${labelPart}:** ${valuePart}`;
                    } else {
                        line = `**${labelPart}:** ${valuePart}`;
                    }
                }
            } else if (/^[•▪]\s+/.test(line)) {
                line = '- ' + line.replace(/^[•▪]\s+/, '');
            }

            // Explicit match for project folders ONLY (data/raw/Dataset, data/interim/final_dataset.csv, notebooks/Data_Understanding.ipynb)
            line = line.replace(/\b((?:data|notebooks|src|models|docs|lib|api|tools|raw|interim)\/[a-zA-Z0-9_.\-\/]+)\b/gi, '`$1`');
            // Explicit match for filenames with extensions ONLY (eda_pulsar.py, final_dataset.csv)
            line = line.replace(/(?<!`)\b([a-zA-Z0-9_-]+\.(?:csv|py|ipynb|docx|pdf|png|jpg|json|html|js|php|exe|txt|md))\b(?!`)/gi, '`$1`');

            result.push(line);
        }

        return result.join('\n');
    }
});
