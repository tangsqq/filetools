<?php

// Load Composer libraries
if (file_exists('vendor/autoload.php')) {
    require 'vendor/autoload.php';
}

use Smalot\PdfParser\Parser;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

// Set up paths for different operating systems 
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    // Windows paths
    $magickPath = 'C:\Program Files\ImageMagick-7.1.2-Q16';
    $gsPath = 'C:\Program Files\gs\gs10.04.1\bin';
    $libreOfficePath = '"C:\Program Files\LibreOffice\program\soffice.exe"';
    putenv("PATH=" . getenv('PATH') . ";" . $magickPath . ";" . $gsPath);
} else {
    // Linux or Docker paths
    $libreOfficePath = 'libreoffice';
    putenv('HOME=/tmp');
}

// Delete folders 
function recursiveRemoveDir($dir)
{
    if (is_dir($dir)) {
        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object != "." && $object != "..") {
                if (is_dir($dir . DIRECTORY_SEPARATOR . $object))
                    recursiveRemoveDir($dir . DIRECTORY_SEPARATOR . $object);
                else
                    @unlink($dir . DIRECTORY_SEPARATOR . $object);
            }
        }
        @rmdir($dir);
    }
}

// Performance limits
set_time_limit(600);
ini_set('memory_limit', '1024M');

$message = "";

if (isset($_POST["submit"])) {
    if (isset($_FILES["fileToUpload"]) && $_FILES["fileToUpload"]["error"] == 0) {
        $tempFile = $_FILES["fileToUpload"]["tmp_name"];
        $targetFormat = $_POST["targetFormat"];
        $originalName = $_FILES["fileToUpload"]["name"];
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $timestamp = time();

        try {
            $outDir = sys_get_temp_dir();

            // Office conversion logic
            $officeExtensions = ['doc', 'docx', 'xls', 'xlsx'];
            if (in_array($extension, $officeExtensions) && $targetFormat === 'pdf') {
                $cmd = "$libreOfficePath --headless --convert-to pdf --outdir " . escapeshellarg($outDir) . " " . escapeshellarg($tempFile);
                shell_exec($cmd);

                $convertedFile = $outDir . DIRECTORY_SEPARATOR . pathinfo($tempFile, PATHINFO_FILENAME) . '.pdf';
                if (!file_exists($convertedFile)) throw new Exception("Office to PDF conversion failed.");

                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="' . pathinfo($originalName, PATHINFO_FILENAME) . '.pdf"');
                setcookie("fileDownload", "true", time() + 30, "/");
                readfile($convertedFile);
                @unlink($convertedFile);
                exit;
            }

            // PDF to Word, PPTX or XLSX
            elseif ($extension === 'pdf' && ($targetFormat === 'docx' || $targetFormat === 'pptx' || $targetFormat === 'xlsx')) {

                $convertedFile = $outDir . DIRECTORY_SEPARATOR . pathinfo($tempFile, PATHINFO_FILENAME) . '.' . $targetFormat;

                if ($targetFormat === 'xlsx') {
                    // Initialize PDF Parser and extract text
                    $parser = new Parser();
                    $pdf = $parser->parseFile($tempFile);
                    $text = $pdf->getText();

                    $spreadsheet = new Spreadsheet();
                    $sheet = $spreadsheet->getActiveSheet();

                    $lines = explode("\n", $text);
                    $row = 1;

                    // Loop through each line and insert data into cells
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if (empty($line)) continue;

                        // Split by 1 or more spaces or tabs to identify columns
                        $columns = preg_split('/\s{1,}|\t/', $line);

                        $col = 1;
                        foreach ($columns as $cellData) {
                            // Get the Column Letter based on numeric index
                            $colLetter = Coordinate::stringFromColumnIndex($col);
                            $sheet->setCellValue($colLetter . $row, $cellData);
                            $col++;
                        }
                        $row++;
                    }

                    // Auto-size column widths based on content
                    $highestColumn = $sheet->getHighestColumn();
                    $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

                    for ($i = 1; $i <= $highestColumnIndex; $i++) {
                        $colLetter = Coordinate::stringFromColumnIndex($i);
                        $sheet->getColumnDimension($colLetter)->setAutoSize(true);
                    }

                    // Save the generated Excel file
                    $writer = new Xlsx($spreadsheet);
                    $writer->save($convertedFile);
                } else {
                    // Use LibreOffice for Word and PPTX
                    $uniqueId = uniqid();
                    $tempUserDir = $outDir . DIRECTORY_SEPARATOR . "lo_profile_" . $uniqueId;
                    if (!is_dir($tempUserDir)) @mkdir($tempUserDir);
                    $loUserPath = "file:///" . str_replace("\\", "/", $tempUserDir);

                    $filter = ($targetFormat === 'docx') ? "writer_pdf_import" : "impress_pdf_import";

                    $cmd = "$libreOfficePath -env:UserInstallation=" . escapeshellarg($loUserPath) .
                        " --headless --infilter=\"$filter\" --convert-to $targetFormat --outdir " .
                        escapeshellarg($outDir) . " " . escapeshellarg($tempFile);

                    shell_exec($cmd);
                    recursiveRemoveDir($tempUserDir);
                }

                if (!file_exists($convertedFile)) {
                    throw new Exception("Conversion to " . strtoupper($targetFormat) . " failed.");
                }

                // Set headers
                if ($targetFormat === 'docx') {
                    $contentType = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
                } elseif ($targetFormat === 'pptx') {
                    $contentType = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
                } else {
                    $contentType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
                }

                header('Content-Type: ' . $contentType);
                header('Content-Disposition: attachment; filename="' . pathinfo($originalName, PATHINFO_FILENAME) . '.' . $targetFormat . '"');
                setcookie("fileDownload", "true", time() + 30, "/");
                readfile($convertedFile);

                @unlink($convertedFile);
                exit;
            }

            // Word -> Excel (Table Aware)
            elseif (($extension === 'doc' || $extension === 'docx')
                && $targetFormat === 'xlsx') {

                $convertedFile = $outDir . DIRECTORY_SEPARATOR .
                    pathinfo($originalName, PATHINFO_FILENAME) . '.xlsx';

                $phpWord = \PhpOffice\PhpWord\IOFactory::load($tempFile);

                $spreadsheet = new Spreadsheet();
                $sheet = $spreadsheet->getActiveSheet();

                $excelRow = 1;
                $tableFound = false;

                foreach ($phpWord->getSections() as $section) {

                    foreach ($section->getElements() as $element) {

                        if ($element instanceof \PhpOffice\PhpWord\Element\Table) {

                            $tableFound = true;

                            foreach ($element->getRows() as $row) {

                                $excelCol = 1;

                                foreach ($row->getCells() as $cell) {

                                    $text = '';

                                    foreach ($cell->getElements() as $cellElement) {

                                        if ($cellElement instanceof \PhpOffice\PhpWord\Element\Text) {
                                            $text .= $cellElement->getText();
                                        }

                                        // TextRun
                                        elseif ($cellElement instanceof \PhpOffice\PhpWord\Element\TextRun) {

                                            foreach ($cellElement->getElements() as $subElement) {

                                                if (method_exists($subElement, 'getText')) {
                                                    $text .= $subElement->getText();
                                                }
                                            }
                                        }
                                    }

                                    $columnLetter =
                                        \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($excelCol);

                                    $sheet->setCellValue(
                                        $columnLetter . $excelRow,
                                        trim($text)
                                    );

                                    $excelCol++;
                                }

                                $excelRow++;
                            }

                            $excelRow += 2;
                        }
                    }
                }

                if (!$tableFound) {

                    $excelRow = 1;

                    foreach ($phpWord->getSections() as $section) {

                        foreach ($section->getElements() as $element) {

                            if (method_exists($element, 'getText')) {

                                $sheet->setCellValue(
                                    'A' . $excelRow,
                                    $element->getText()
                                );

                                $excelRow++;
                            }
                        }
                    }
                }

                $highestColumn = $sheet->getHighestColumn();

                $highestColumnIndex =
                    \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString(
                        $highestColumn
                    );

                for ($i = 1; $i <= $highestColumnIndex; $i++) {

                    $columnLetter =
                        \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);

                    $sheet->getColumnDimension($columnLetter)
                        ->setAutoSize(true);
                }

                $writer = new Xlsx($spreadsheet);
                $writer->save($convertedFile);

                header(
                    'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                );

                header(
                    'Content-Disposition: attachment; filename="' .
                    pathinfo($originalName, PATHINFO_FILENAME) .
                    '.xlsx"'
                );

                setcookie("fileDownload", "true", time() + 30, "/");

                readfile($convertedFile);

                @unlink($convertedFile);

                exit;
            }

            // Imagick logic for image conversion 
            if (!class_exists('Imagick')) {
                throw new Exception("Imagick not installed.");
            }

            $identify = new Imagick();
            $identify->pingImage(realpath($tempFile));
            $numPages = $identify->getNumberImages();
            $identify->clear();
            $identify->destroy();

            if ($numPages <= 1 || strtolower($targetFormat) === 'pdf') {
                $image = new Imagick();
                if (strtolower($extension) === 'pdf') {
                    $image->setResolution(150, 150);
                }
                $image->readImage(realpath($tempFile));

                $image->setImageBackgroundColor('white');
                $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                $image = $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
                $image->setImageFormat($targetFormat);

                $fileData = $image->getImagesBlob();
                $outputFileName = 'converted_' . $timestamp . '.' . $targetFormat;

                header('Content-Description: File Transfer');
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . $outputFileName . '"');
                setcookie("fileDownload", "true", time() + 30, "/");
                echo $fileData;
                exit;
            } else {
                if (!class_exists('ZipArchive')) {
                    throw new Exception("Zip extension not enabled.");
                }

                $zip = new ZipArchive();
                $zipFileName = 'converted_pages_' . $timestamp . '.zip';
                $zipPath = $outDir . DIRECTORY_SEPARATOR . $zipFileName;

                if ($zip->open($zipPath, ZipArchive::CREATE) !== TRUE) {
                    throw new Exception("Cannot create zip file.");
                }

                for ($i = 0; $i < $numPages; $i++) {
                    $page = new Imagick();
                    $page->setResolution(150, 150);
                    $page->readImage(realpath($tempFile) . '[' . $i . ']');
                    $page->setImageBackgroundColor('white');
                    $page->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                    $page->setImageFormat($targetFormat);
                    $single = $page->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
                    $zip->addFromString("page_" . ($i + 1) . "." . $targetFormat, $single->getImagesBlob());
                    $single->clear();
                    $single->destroy();
                    $page->clear();
                    $page->destroy();
                }
                $zip->close();

                if (ob_get_length()) ob_end_clean();
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . $zipFileName . '"');
                header('Content-Length: ' . filesize($zipPath));
                setcookie("fileDownload", "true", time() + 30, "/");
                readfile($zipPath);
                @unlink($zipPath);
                exit;
            }
        } catch (Exception $e) {
            $message = "<div style='color:red;'>Error: " . $e->getMessage() . "</div>";
        }
    } else {
        $message = "<div style='color:red;'>Please upload a valid file.</div>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>📑</text></svg>">
    <title>File Converter</title>
    <style>
        :root {
            --primary: #111827;
            --primary-hover: #000;
        }

        /* Pink Theme */
        body[data-theme="pink"] {
            --primary: #ec4899;
            --primary-hover: #db2777;
        }

        body[data-theme="pink"] .container {
            border-color: var(--primary);
        }

        body[data-theme="pink"] h2 {
            color: var(--primary);
        }

        body[data-theme="pink"] input[type="submit"] {
            background: var(--primary) !important;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: white;
            background-image: radial-gradient(#e2e8f0 1px, transparent 1px);
            background-size: 20px 20px;
            margin: 0;
            color: #1e293b;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }

        .container {
            background: white;
            padding: 40px 35px;
            border-radius: 20px;
            border: 1px solid #000;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08), 0 2px 10px rgba(0, 0, 0, 0.05);
            width: 100%;
            max-width: 420px;
            transition: all 0.3s ease;
        }

        .top-right-controls {
            position: absolute;
            top: 15px;
            right: 15px;
            display: flex;
            align-items: center;
            gap: 15px;
            z-index: 10;
        }

        .help-icon {
            color: #94a3b8;
            cursor: pointer;
            font-size: 18px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 10;
        }

        .help-icon:hover {
            color: var(--primary, #111827);
            transform: scale(1.2) rotate(15deg);
        }

        .modal-animating {
            animation: modalShow 0.4s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
        }

        .modal-closing {
            animation: modalHide 0.2s ease-in forwards;
        }

        @keyframes modalShow {
            from {
                opacity: 0;
                transform: scale(0.9) translateY(-20px);
            }

            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        @keyframes modalHide {
            from {
                opacity: 1;
                transform: scale(1);
            }

            to {
                opacity: 0;
                transform: scale(0.95);
            }
        }

        #help-content {
            text-align: left;
            font-size: 14px;
            line-height: 1.6;
            padding: 10px 5px;
        }

        #help-content ul {
            text-align: left;
            margin: 10px 0 0 0;
            padding-left: 0;
            list-style-type: none;
        }

        #help-content li {
            margin-bottom: 10px;
        }

        .container {
            position: relative;
        }

        h2 {
            color: #111827;
            text-align: center;
            margin-bottom: 30px;
            font-weight: 600;
            letter-spacing: -0.5px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 14px;
            color: var(--primary, #374151);
        }

        .file-upload-label {
            padding: 12px 22px;
            border-radius: 20px;
            background: white;
            color: var(--primary, black);
            border: 1px dashed #85878a;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.25s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            height: 44px;
            width: 100%;
            justify-content: center;
            box-sizing: border-box;
            overflow: hidden;
            white-space: nowrap;
        }

        .file-upload-label:hover {
            background: lightgray;
            border-color: lightgray;
        }

        input[type="file"] {
            display: none;
        }

        .select-wrapper {
            position: relative;
            width: 100%;
            margin-bottom: 22px;
        }

        .select-wrapper select {
            width: 100%;
            height: 44px;
            padding: 0 40px 0 14px;
            border-radius: 20px;
            border: 1px solid var(--primary, black);
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            font-size: 14px;
            font-weight: 600;
            color: var(--primary, black);
            cursor: pointer;
            background-color: white;
            outline: none;
            transition: border-color 0.25s ease;
        }

        .select-wrapper:hover select {
            border: 2px solid var(--primary) !important;
            padding: 0 39px 0 13px;
        }

        .select-wrapper::after {
            font-family: "Font Awesome 6 Free";
            content: "\f0d7";
            font-weight: 900;
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
            font-size: 20px;
            color: var(--primary, black);
        }

        .select-wrapper select:focus {
            border-color: var(--primary) !important;
        }

        .select-wrapper:hover select,
        .select-wrapper select:focus {
            border-color: var(--primary) !important;
        }

        input[type="submit"] {
            width: 100%;
            background: var(--primary, #111827);
            color: white;
            border: none;
            padding: 13px;
            border-radius: 20px;
            cursor: pointer;
            font-size: 15px;
            font-weight: 600;
            letter-spacing: .5px;
            transition: all 0.25s ease;
        }

        input[type="submit"]:hover {
            background: var(--primary, #000);
            transform: translateY(-1px);
            box-shadow: 0 5px 12px rgba(0, 0, 0, 0.2);
        }

        .result {
            margin-top: 20px;
            padding: 15px;
            background: #f9fafb;
            border-left: 4px solid var(--primary, #111827);
            border-radius: 6px;
            font-size: 14px;
            word-break: break-word;
        }

        #loadingOverlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(6px);
            z-index: 9999;
        }

        .loading-box {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            background: white;
            padding: 45px 50px;
            border-radius: 20px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.12);
        }

        .spinner {
            border: 4px solid #f1f5f9;
            border-top: 4px solid #111827;
            border-radius: 50%;
            width: 42px;
            height: 42px;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .home-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            color: var(--primary, #1e293b);
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

        /* Modal Alert Styles */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 10001;
            justify-content: center;
            align-items: center;
        }

        .btn-main {
            background: var(--primary, #111827) !important;
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 20px;
            cursor: pointer;
            font-weight: 600;
            margin-top: 15px;
        }

        .btn-main:hover {
            background: var(--primary-hover) !important;
            transform: translateY(-1px);
        }

        #alertTitle {
            color: var(--primary);
            margin-top: 0;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="top-right-controls">
            <div class="theme-switcher">
                <button onclick="setTheme('default')" title="Default Theme" style="background:#111827; width:16px; height:16px; border-radius:50%; border:2px solid #fff; cursor:pointer; box-shadow: 0 2px 4px rgba(0,0,0,0.1);"></button>
                <button onclick="setTheme('pink')" title="Pink Theme" style="background:#ec4899; width:16px; height:16px; border-radius:50%; border:2px solid #fff; cursor:pointer; box-shadow: 0 2px 4px rgba(0,0,0,0.1);"></button>
            </div>
            <i class="fa-regular fa-circle-question help-icon" title="How to use" onclick="showHelp()"></i>
        </div>
        <h2>File Converter</h2>
        <form id="convertForm" action="" method="post" enctype="multipart/form-data">
            <label>Choose File</label>
            <label for="fileToUpload" class="file-upload-label">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                </svg>
                <span id="file-name-text">Choose Files</span>
            </label>
            <input type="file" id="fileToUpload" name="fileToUpload" accept="application/pdf, .xlsx, .xls, .doc, .docx, .ppt, .pptx, .jpg, .jpeg, .png">

            <label>Convert To</label>
            <div class="select-wrapper">
                <select name="targetFormat">
                    <option value="pdf">PDF (.pdf)</option>
                    <option value="docx">Word (.docx)</option>
                    <option value="pptx">PowerPoint (.pptx)</option>
                    <option value="xlsx">Excel (.xlsx)</option>
                </select>
            </div>
            <input type="submit" value="Convert" name="submit">
            <a href="index.html" class="home-btn" title="Back to Home"><i class="fa fa-home"></i></a>
        </form>
        <?php if ($message): ?>
            <div class="result"><?php echo $message; ?></div>
        <?php endif; ?>
    </div>

    <div id="customAlert" class="modal-overlay">
        <div style="background: white; padding: 32px; border-radius: 25px; text-align: center; max-width: 360px; width: 90%;">
            <h3 id="alertTitle">Status</h3>
            <p id="alertMessage"></p>
            <button class="btn btn-main" id="alertBtn" onclick="closeAlert()">OK</button>
        </div>
    </div>

    <div id="loadingOverlay">
        <div class="loading-box">
            <div class="spinner"></div>
            <p style="margin:0; font-weight:bold; color:#333;">Processing...</p>
            <p style="margin:10px 0 0; font-size:13px; color:#999;">Please wait...</p>
        </div>
    </div>

    <script>
        function setTheme(theme) {
            if (theme === 'pink') {
                document.body.setAttribute('data-theme', 'pink');
                localStorage.setItem('selected-theme', 'pink');
            } else {
                document.body.removeAttribute('data-theme');
                localStorage.setItem('selected-theme', 'default');
            }
        }

        // Apply saved theme on page load
        document.addEventListener('DOMContentLoaded', () => {
            const savedTheme = localStorage.getItem('selected-theme');
            if (savedTheme === 'pink') setTheme('pink');
        });

        function showHelp() {
            const helpText = `
            <div id="help-content">
                <strong>Support:</strong>
                <ul>
                    <li><strong>Word - PDF</li>
                     <li><strong>Word - Excel</li>
                    <li><strong>Excel - PDF</li>
                    <li><strong>PDF - Word</li>
                    <li><strong>PDF - PPT</li>
                    <li><strong>PDF - Excel</li>
                </ul>
            </div>
        `;

            const alertModal = document.getElementById('customAlert');
            const modalContent = alertModal.querySelector('div');

            document.getElementById('alertTitle').innerText = "How to Use";
            document.getElementById('alertMessage').innerHTML = helpText;

            modalContent.style.maxWidth = "500px";

            alertModal.style.display = 'flex';
            modalContent.classList.remove('modal-closing');
            modalContent.classList.add('modal-animating');
        }

        function showAlert(message, title = "Status") {
            const alertModal = document.getElementById('customAlert');
            const modalContent = alertModal.querySelector('div');

            document.getElementById('alertTitle').innerText = title;
            document.getElementById('alertMessage').innerText = message;

            alertModal.style.display = 'flex';
            modalContent.classList.remove('modal-closing');
            modalContent.classList.add('modal-animating');
        }

        function closeAlert() {
            const alertModal = document.getElementById('customAlert');
            const modalContainer = alertModal.querySelector('div');

            modalContainer.classList.remove('modal-animating');
            modalContainer.classList.add('modal-closing');

            setTimeout(() => {
                alertModal.style.display = 'none';
                modalContainer.classList.remove('modal-closing');

                document.getElementById('alertTitle').innerText = "Status";
                document.getElementById('alertMessage').innerText = "";
                modalContainer.style.maxWidth = "360px";
            }, 200);
        }

        function showAlert(message, title = "Status") {
            document.getElementById('alertTitle').innerText = title;
            document.getElementById('alertMessage').innerText = message;
            document.getElementById('customAlert').style.display = 'flex';
        }

        function closeAlert() {
            document.getElementById('customAlert').style.display = 'none';
        }

        document.getElementById('fileToUpload').onchange = function() {
            if (this.files && this.files.length > 0) {
                document.getElementById('file-name-text').innerText = this.files[0].name;
            }
        };

        document.getElementById('convertForm').onsubmit = function() {
            if (document.getElementById('fileToUpload').files.length === 0) {
                showAlert("Please select a file first.", "Notice");
                return false;
            }

            document.getElementById('loadingOverlay').style.display = 'block';
            document.cookie = "fileDownload=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
            var checkTimer = setInterval(function() {
                if (document.cookie.indexOf("fileDownload=true") !== -1) {
                    document.getElementById('loadingOverlay').style.display = 'none';
                    document.cookie = "fileDownload=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
                    clearInterval(checkTimer);
                }
            }, 500);
        };
    </script>
</body>

</html>
