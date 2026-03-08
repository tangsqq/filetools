<?php
if (isset($_FILES['excel_file'])) {
    header('Content-Type: application/json');

    // 1. 对应 Dockerfile 中创建的目录，或使用系统临时目录
    $uploadDir = '/tmp/pdf_tool_';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $file = $_FILES['excel_file'];
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $tmpFilePath = $uploadDir . '/' . uniqid() . '_' . $file['name'];
    move_uploaded_file($file['tmp_name'], $tmpFilePath);

    // 支持的格式
    $officeExtensions = ['xlsx', 'xls', 'csv', 'doc', 'docx', 'ppt', 'pptx'];
    $imageExtensions = ['jpg', 'jpeg', 'png'];

    // 2. 【核心修改】在 Docker (Linux) 中，soffice 通常直接在环境变量中
    // 且必须添加 --user-installation 参数防止权限冲突
    $sofficePath = 'libreoffice';
    $userConfigDir = '/tmp/libreoffice_user_' . uniqid();

    if (in_array($extension, $officeExtensions) || in_array($extension, $imageExtensions)) {
        // 构建命令：指定输出目录，使用 headless 模式
        $cmd = "$sofficePath \"-env:UserInstallation=file://$userConfigDir\" --headless --convert-to pdf --outdir " . escapeshellarg($uploadDir) . " " . escapeshellarg($tmpFilePath) . " 2>&1";

        exec($cmd, $output, $returnVar);

        if ($returnVar === 0) {
            $pdfPath = $uploadDir . '/' . pathinfo($tmpFilePath, PATHINFO_FILENAME) . '.pdf';

            if (file_exists($pdfPath)) {
                echo json_encode([
                    'success' => true,
                    'pdf_base64' => base64_encode(file_get_contents($pdfPath)),
                    'filename' => $file['name']
                ]);
                @unlink($pdfPath);
            } else {
                echo json_encode(['success' => false, 'error' => 'PDF not generated. Debug: ' . implode("\n", $output)]);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Conversion failed', 'debug' => $output]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Unsupported format']);
    }

    // 清理临时文件和 LibreOffice 产生的用户配置目录
    @unlink($tmpFilePath);
    if (is_dir($userConfigDir)) {
        exec("rm -rf " . escapeshellarg($userConfigDir));
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>📑</text></svg>">
    <title>PDF Reorder, Rotate & Split</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.4.120/pdf.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://unpkg.com/pdf-lib@1.17.1/dist/pdf-lib.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --split: #f87171;
            --bg: #f8fafc;
            --card-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
        }

        body {
            font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
            background-color: var(--bg);
            background-image: radial-gradient(#e2e8f0 1px, transparent 1px);
            background-size: 20px 20px;
            margin: 0;
            padding: 40px 20px;
            color: #1e293b;
            min-height: 100vh;
        }

        .setup-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            padding: 30px;
            border-radius: 24px;
            box-shadow: var(--card-shadow);
            text-align: center;
            max-width: 800px;
            margin: 0 auto 40px;
            border: 1px solid #000;
        }

        .setup-card h2 {
            margin: 0 0 10px 0;
            font-weight: 800;
            letter-spacing: -0.025em;
            background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .btn {
            padding: 10px 24px;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            height: 42px;
            box-sizing: border-box;
        }

        .btn-main {
            background: black;
            color: white;
            border-radius: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .btn-main:hover {
            background: #334155;
            transform: translateY(-1px);
        }

        .btn-clear {
            background: #fff;
            color: #64748b;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
        }

        .btn-clear:hover {
            background: #f1f5f9;
            color: #0f172a;
        }

        #file-selector {
            display: none;
        }

        .file-upload-label {
            padding: 10px 24px;
            border-radius: 20px;
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #e2e8f0;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            height: 42px;
            box-sizing: border-box;
        }

        .workspace-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
            gap: 30px;
            padding: 30px;
            background: rgba(255, 255, 255, 0.5);
            border-radius: 25px;
            border: 2px dashed #cbd5e1;
            min-height: 400px;
            max-width: 1200px;
            margin: 0 auto;
            position: relative;
        }

        .drop-hint {
            grid-column: 1 / -1;
            text-align: center;
            color: #94a3b8;
            padding-top: 150px;
            pointer-events: none;
        }

        .drop-hint i {
            font-size: 50px;
            margin-bottom: 15px;
            display: block;
        }

        .segment-header {
            grid-column: 1 / -1;
            display: flex;
            align-items: center;
            gap: 12px;
            background: white;
            padding: 15px 25px;
            border-radius: 18px;
            margin-top: 15px;
            border: 1px solid #e2e8f0;
        }

        .rename-input {
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 8px 15px;
            font-size: 14px;
            flex-grow: 1;
            max-width: 400px;
            outline: none;
        }

        .page-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            padding: 12px;
            cursor: grab;
            position: relative;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
            user-select: none;
        }

        .page-card:hover {
            border-color: var(--primary);
            transform: translateY(-5px);
        }

        canvas {
            width: 100%;
            height: auto;
            border-radius: 20px;
            display: block;
            transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
            background: #f8fafc;
        }

        .badge {
            position: absolute;
            top: -12px;
            left: 12px;
            background: #1e293b;
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            z-index: 5;
        }

        .rotate-btn {
            position: absolute;
            bottom: 35px;
            right: 18px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            width: 32px;
            height: 32px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
            background: white;
        }

        .delete-btn {
            position: absolute;
            top: -12px;
            right: 12px;
            background: var(--split);
            color: white;
            border: none;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            cursor: pointer;
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 15;
        }

        .page-card:hover .delete-btn {
            display: flex;
        }

        .page-card.split-active {
            border-right: 4px dashed var(--split);
            margin-right: 5px;
        }

        .page-card.split-active::after {
            content: '✂️';
            position: absolute;
            right: -16px;
            top: 50%;
            transform: translateY(-50%);
            background: white;
            padding: 2px;
        }

        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }

        /* --- Updated Preview Styles --- */
        #previewModal {
            background: rgba(0, 0, 0, 0.95);
            overflow: hidden;
        }

        #previewImage {
            max-width: 90%;
            max-height: 90vh;
            box-shadow: 0 0 40px rgba(0, 0, 0, 0.8);
            cursor: grab;
            user-select: none;
            transition: transform 0.1s ease-out;
            /* Smooth zoom transition */
        }

        #previewImage:active {
            cursor: grabbing;
        }

        .nav-arrow {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: none;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s;
            z-index: 10001;
        }

        .nav-arrow:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        #prevArrow {
            left: 30px;
        }

        #nextArrow {
            right: 30px;
        }

        .home-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            color: #1e293b;
            font-size: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: all 0.3s ease;
            z-index: 10000;
        }

        .home-btn:hover {
            transform: scale(1.1);
            color: lightgray;
        }

        .home-btn i {
            font-size: 30px;
        }
    </style>
</head>

<body>
    <div class="setup-card">
        <h2>PDF Reorder, Rotate & Split</h2>
        <p>Right-Click: Split | Scroll: Zoom | Drag Image: Move | Drag Card: Reorder</p>

        <div style="display: flex; align-items: center; justify-content: center; gap: 12px; flex-wrap: wrap;">
            <label for="file-selector" class="file-upload-label">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                </svg>
                Choose Files
            </label>
            <input type="file" id="file-selector" accept="application/pdf, .xlsx, .xls, .doc, .docx, .ppt, .pptx, .jpg, .jpeg, .png" multiple>
            <button class="btn btn-main" onclick="exportPDF()">Download All</button>
            <button class="btn btn-clear" onclick="location.reload()">Clear All</button>
        </div>
    </div>

    <div id="workspace" class="workspace-grid">
        <div class="drop-hint" id="drop-hint"><i class="fa-solid fa-file-arrow-up"></i>Drag and Drop files here</div>
    </div>

    <div id="customAlert" class="modal-overlay">
        <div style="background: white; padding: 32px; border-radius: 25px; text-align: center; max-width: 360px; width: 90%;">
            <h3 id="alertTitle">Status</h3>
            <p id="alertMessage"></p>
            <button class="btn btn-main" id="alertBtn" onclick="closeAlert()">OK</button>
        </div>
    </div>

    <div id="previewModal" class="modal-overlay" onclick="closePreview()">
        <button class="nav-arrow" id="prevArrow" onclick="navigatePreview(-1, event)"><i class="fa fa-chevron-left"></i></button>
        <img id="previewImage" src="" onclick="event.stopPropagation()">
        <button class="nav-arrow" id="nextArrow" onclick="navigatePreview(1, event)"><i class="fa fa-chevron-right"></i></button>
    </div>

    <a href="index.html" class="home-btn" title="Back to Home"><i class="fa fa-home"></i></a>

    <script>
        const {
            PDFDocument,
            degrees
        } = PDFLib;
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.4.120/pdf.worker.min.js';

        let sourcePdfs = new Map();
        let currentPreviewIdx = -1;
        let state = {
            pageOrder: [],
            splits: new Set(),
            segmentNames: {}
        };

        // Zoom & Drag State
        let zoomLevel = 1;
        let isDragging = false;
        let startX, startY;
        let translateX = 0,
            translateY = 0;

        function cloneBuffer(buffer) {
            const dst = new ArrayBuffer(buffer.byteLength);
            new Uint8Array(dst).set(new Uint8Array(buffer));
            return dst;
        }

        const workspace = document.getElementById('workspace');

        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            document.addEventListener(eventName, e => {
                e.preventDefault();
                e.stopPropagation();
            }, false);
        });

        workspace.addEventListener('drop', (e) => {
            const files = e.dataTransfer.files;
            if (files.length > 0) handleFiles(Array.from(files));
        });

        document.getElementById('file-selector').addEventListener('change', (e) => {
            handleFiles(Array.from(e.target.files));
        });

        async function handleFiles(files) {
            if (files.length === 0) return;
            showAlert("Processing files...");
            for (const file of files) {
                const fileId = crypto.randomUUID();
                try {
                    let rawBuffer;
                    const needsConversion = file.name.match(/\.(xlsx|xls|doc|docx|ppt|pptx|jpg|jpeg|png)$/i);
                    if (needsConversion) {
                        const formData = new FormData();
                        formData.append('excel_file', file);
                        const response = await fetch('', {
                            method: 'POST',
                            body: formData
                        });
                        const result = await response.json();
                        if (!result.success) throw new Error(result.error);
                        const binaryStr = atob(result.pdf_base64);
                        const bytes = new Uint8Array(binaryStr.length);
                        for (let i = 0; i < binaryStr.length; i++) bytes[i] = binaryStr.charCodeAt(i);
                        rawBuffer = bytes.buffer;
                    } else if (file.type === "application/pdf") {
                        rawBuffer = await file.arrayBuffer();
                    } else continue;

                    sourcePdfs.set(fileId, {
                        buffer: cloneBuffer(rawBuffer),
                        pdfjsDoc: null
                    });
                    const pdfjsDoc = await pdfjsLib.getDocument({
                        data: cloneBuffer(rawBuffer)
                    }).promise;
                    sourcePdfs.get(fileId).pdfjsDoc = pdfjsDoc;

                    for (let i = 0; i < pdfjsDoc.numPages; i++) {
                        state.pageOrder.push({
                            fileId: fileId,
                            originalIdx: i,
                            rotation: 0,
                            fileName: file.name
                        });
                    }
                } catch (err) {
                    alert("Error: " + err.message);
                }
            }
            closeAlert();
            renderUI();
        }

        function renderUI() {
            workspace.innerHTML = '';
            if (state.pageOrder.length === 0) {
                workspace.innerHTML = `<div class="drop-hint"><i class="fa fa-cloud-upload"></i>Drag and Drop PDF, Office or Image files here</div>`;
                return;
            }

            let segmentIndex = 0;
            const createRenameBar = (idx) => {
                const div = document.createElement('div');
                div.className = 'segment-header';
                const defaultName = `Document_Part_${idx + 1}`;
                div.innerHTML = `
                    <span class="segment-label">File ${idx + 1}:</span>
                    <input type="text" class="rename-input" value="${state.segmentNames[idx] || defaultName}" oninput="state.segmentNames[${idx}] = this.value">
                    <button class="btn btn-main" style="height:34px; font-size:12px;" onclick="downloadSingleGroup(${idx})">Download</button>
                `;
                return div;
            };

            workspace.appendChild(createRenameBar(segmentIndex++));

            state.pageOrder.forEach((pageObj, currentPos) => {
                const card = document.createElement('div');
                card.className = `page-card ${state.splits.has(currentPos) ? 'split-active' : ''}`;
                card.draggable = true;
                const canvasId = `canvas-${pageObj.fileId}-${pageObj.originalIdx}-${currentPos}`;

                card.innerHTML = `
                    <div class="badge">#${currentPos + 1}</div>
                    <button class="delete-btn"><i class="fa fa-times"></i></button>
                    <canvas id="${canvasId}"></canvas>
                    <button class="rotate-btn"><i class="fa fa-rotate-right"></i></button>
                    <div style="font-size:11px; color:#94a3b8; margin-top:8px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${pageObj.fileName}</div>
                `;

                card.onclick = () => showPreview(currentPos);
                card.oncontextmenu = (e) => {
                    e.preventDefault();
                    if (currentPos === state.pageOrder.length - 1) return;
                    state.splits.has(currentPos) ? state.splits.delete(currentPos) : state.splits.add(currentPos);
                    renderUI();
                };

                card.querySelector('.rotate-btn').onclick = (e) => {
                    e.stopPropagation();
                    pageObj.rotation = (pageObj.rotation + 90) % 360;
                    renderUI();
                };

                card.querySelector('.delete-btn').onclick = (e) => {
                    e.stopPropagation();
                    state.pageOrder.splice(currentPos, 1);
                    renderUI();
                };

                card.ondragstart = (e) => {
                    e.dataTransfer.setData('text/plain', currentPos);
                    card.classList.add('dragging');
                };
                card.ondrop = (e) => {
                    const fromPos = parseInt(e.dataTransfer.getData('text/plain'));
                    const item = state.pageOrder.splice(fromPos, 1)[0];
                    state.pageOrder.splice(currentPos, 0, item);
                    renderUI();
                };

                workspace.appendChild(card);
                drawThumb(pageObj, canvasId);
                if (state.splits.has(currentPos)) workspace.appendChild(createRenameBar(segmentIndex++));
            });
        }

        async function drawThumb(pageObj, canvasId) {
            try {
                const source = sourcePdfs.get(pageObj.fileId);
                const page = await source.pdfjsDoc.getPage(pageObj.originalIdx + 1);
                const viewport = page.getViewport({
                    scale: 1.5
                });
                const canvas = document.getElementById(canvasId);
                if (!canvas) return;
                const ctx = canvas.getContext('2d');
                canvas.height = viewport.height;
                canvas.width = viewport.width;
                canvas.style.transform = `rotate(${pageObj.rotation}deg)`;
                await page.render({
                    canvasContext: ctx,
                    viewport
                }).promise;
            } catch (e) {
                console.error(e);
            }
        }

        // --- Improved Preview & Zoom/Drag Functions ---
        function updateImageTransform() {
            const img = document.getElementById('previewImage');
            const pageObj = state.pageOrder[currentPreviewIdx];
            const rotation = pageObj ? `rotate(${pageObj.rotation}deg)` : 'rotate(0deg)';
            img.style.transform = `translate(${translateX}px, ${translateY}px) ${rotation} scale(${zoomLevel})`;
        }

        function showPreview(index) {
            zoomLevel = 1;
            translateX = 0;
            translateY = 0;
            currentPreviewIdx = index;
            const pageObj = state.pageOrder[index];
            const canvasId = `canvas-${pageObj.fileId}-${pageObj.originalIdx}-${index}`;
            const sourceCanvas = document.getElementById(canvasId);
            if (!sourceCanvas) return;

            const img = document.getElementById('previewImage');
            img.src = sourceCanvas.toDataURL('image/png');
            updateImageTransform();

            document.getElementById('previewModal').style.display = 'flex';

            const homeBtn = document.querySelector('.home-btn');
            if (homeBtn) homeBtn.style.display = 'none';

            document.getElementById('prevArrow').style.visibility = index > 0 ? 'visible' : 'hidden';
            document.getElementById('nextArrow').style.visibility = index < state.pageOrder.length - 1 ? 'visible' : 'hidden';
        }

        function navigatePreview(direction, event) {
            event.stopPropagation();
            const newIdx = currentPreviewIdx + direction;
            if (newIdx >= 0 && newIdx < state.pageOrder.length) showPreview(newIdx);
        }

        function closePreview() {
            isDragging = false;
            document.getElementById('previewModal').style.display = 'none';

            const homeBtn = document.querySelector('.home-btn');
            if (homeBtn) homeBtn.style.display = 'flex';
        }

        // Zoom and Drag Listeners
        const modal = document.getElementById('previewModal');
        const previewImg = document.getElementById('previewImage');

        modal.addEventListener('wheel', (e) => {
            e.preventDefault();
            const delta = e.deltaY > 0 ? -0.1 : 0.1;
            zoomLevel = Math.min(Math.max(0.5, zoomLevel + delta), 5);
            updateImageTransform();
        }, {
            passive: false
        });

        previewImg.addEventListener('mousedown', (e) => {
            e.preventDefault();
            e.stopPropagation();
            isDragging = true;
            startX = e.clientX - translateX;
            startY = e.clientY - translateY;
        });

        window.addEventListener('mousemove', (e) => {
            if (!isDragging) return;
            translateX = e.clientX - startX;
            translateY = e.clientY - startY;
            updateImageTransform();
        });

        window.addEventListener('mouseup', () => isDragging = false);

        // --- Original Export/Download Logic ---
        async function downloadSingleGroup(index) {
            showAlert("Preparing download...");
            try {
                const groups = splitIntoGroups();
                const bytes = await generatePdfBlob(groups[index]);
                downloadBlob(bytes, state.segmentNames[index] || `Document_Part_${index + 1}`);
                closeAlert();
            } catch (err) {
                showAlert("Error: " + err.message);
            }
        }

        async function exportPDF() {
            if (state.pageOrder.length === 0) return showAlert("No pages to export.");
            showAlert("Generating ZIP file...");
            try {
                const zip = new JSZip();
                const groups = splitIntoGroups();
                for (let i = 0; i < groups.length; i++) {
                    const bytes = await generatePdfBlob(groups[i]);
                    const name = state.segmentNames[i] || `Document_Part_${i + 1}`;
                    zip.file(name.toLowerCase().endsWith('.pdf') ? name : name + '.pdf', bytes);
                }
                const zipContent = await zip.generateAsync({
                    type: "blob"
                });
                const url = URL.createObjectURL(zipContent);
                const a = document.createElement('a');
                a.href = url;
                a.download = "converted_documents.zip";
                a.click();
                showAlert("ZIP Downloaded!");
            } catch (err) {
                showAlert("Export Error: " + err.message);
            }
        }

        function splitIntoGroups() {
            const sortedSplits = Array.from(state.splits).sort((a, b) => a - b);
            let start = 0;
            const groups = [];
            for (const point of sortedSplits) {
                groups.push(state.pageOrder.slice(start, point + 1));
                start = point + 1;
            }
            groups.push(state.pageOrder.slice(start));
            return groups;
        }

        async function generatePdfBlob(group) {
            const pdfLibDocs = new Map();
            for (let [id, source] of sourcePdfs) pdfLibDocs.set(id, await PDFDocument.load(cloneBuffer(source.buffer)));
            const newDoc = await PDFDocument.create();
            for (const pageObj of group) {
                const srcDoc = pdfLibDocs.get(pageObj.fileId);
                const [copiedPage] = await newDoc.copyPages(srcDoc, [pageObj.originalIdx]);
                if (pageObj.rotation !== 0) copiedPage.setRotation(degrees(pageObj.rotation));
                newDoc.addPage(copiedPage);
            }
            return await newDoc.save();
        }

        function downloadBlob(bytes, filename) {
            const blob = new Blob([bytes], {
                type: 'application/pdf'
            });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename.toLowerCase().endsWith('.pdf') ? filename : filename + '.pdf';
            a.click();
        }

        function showAlert(msg) {
            document.getElementById('alertMessage').innerText = msg;
            document.getElementById('customAlert').style.display = 'flex';
        }

        function closeAlert() {
            document.getElementById('customAlert').style.display = 'none';
        }
    </script>
</body>


</html>



