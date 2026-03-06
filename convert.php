<?php

/**
 * Advanced PDF/Image/Office Conversion Tool (Optimized for LibreOffice PDF to Word)
 */

// 引入 Composer 自动加载
if (file_exists('vendor/autoload.php')) {
    require 'vendor/autoload.php';
}

use Smalot\PdfParser\Parser;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;

// --- 路径兼容性处理 ---
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    // Windows 路径
    $magickPath = 'C:\Program Files\ImageMagick-7.1.2-Q16';
    $gsPath = 'C:\Program Files\gs\gs10.04.1\bin';
    $libreOfficePath = '"C:\Program Files\LibreOffice\program\soffice.exe"';
    putenv("PATH=" . getenv('PATH') . ";" . $magickPath . ";" . $gsPath);
} else {
    // Docker / Linux 路径
    $libreOfficePath = 'libreoffice';
    putenv('HOME=/tmp');
}

// 辅助函数：递归删除目录（用于清理 LibreOffice 产生的配置环境）
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

            // --- Office 相关转换逻辑 (Excel/Word to PDF) ---
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

            // --- PDF to Word (使用 LibreOffice 优化指令) ---
            elseif ($extension === 'pdf' && $targetFormat === 'docx') {
                // 为防止 LibreOffice 报错，创建一个临时的 UserProfile 环境
                $uniqueId = uniqid();
                $tempUserDir = $outDir . DIRECTORY_SEPARATOR . "lo_profile_" . $uniqueId;
                if (!is_dir($tempUserDir)) @mkdir($tempUserDir);

                // 强制指定过滤器 writer_pdf_import 可以更好地处理 PDF 里的图片和文本框
                // 注意 Windows 路径下 file:/// 协议的特殊处理
                $loUserPath = "file:///" . str_replace("\\", "/", $tempUserDir);

                $cmd = "$libreOfficePath -env:UserInstallation=" . escapeshellarg($loUserPath) .
                    " --headless --infilter=\"writer_pdf_import\" --convert-to docx --outdir " .
                    escapeshellarg($outDir) . " " . escapeshellarg($tempFile);

                shell_exec($cmd);

                $convertedFile = $outDir . DIRECTORY_SEPARATOR . pathinfo($tempFile, PATHINFO_FILENAME) . '.docx';

                if (!file_exists($convertedFile)) {
                    recursiveRemoveDir($tempUserDir);
                    throw new Exception("LibreOffice failed to convert PDF to DOCX. Please check if the PDF is protected.");
                }

                header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
                header('Content-Disposition: attachment; filename="' . pathinfo($originalName, PATHINFO_FILENAME) . '.docx"');
                setcookie("fileDownload", "true", time() + 30, "/");
                readfile($convertedFile);

                // 清理工作
                @unlink($convertedFile);
                recursiveRemoveDir($tempUserDir);
                exit;
            }

            // --- Imagick 相关逻辑 (保持不变) ---
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
            color: #374151;
        }

        .file-upload-label {
            padding: 12px 22px;
            border-radius: 20px;
            background: white;
            color: black;
            border: 1px dashed black;
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
            border: 1px solid black;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            font-size: 14px;
            font-weight: 600;
            color: black;
            cursor: pointer;
            background-color: white;
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
            color: black;
        }

        input[type="submit"] {
            width: 100%;
            background: #111827;
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
            background: #000;
            transform: translateY(-1px);
            box-shadow: 0 5px 12px rgba(0, 0, 0, 0.2);
        }

        .result {
            margin-top: 20px;
            padding: 15px;
            background: #f9fafb;
            border-left: 4px solid #111827;
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
            color: #1f2937;
            text-decoration: none;
            transition: all 0.25s ease;
            z-index: 10000;
        }

        .home-btn i {
            font-size: 30px;
        }
    </style>
</head>

<body>
    <div class="container">
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
                    <option value="jpg">JPG (.jpg)</option>
                    <option value="png">PNG (.png)</option>
                </select>
            </div>
            <input type="submit" value="Convert" name="submit">
            <a href="index.html" class="home-btn" title="Back to Home"><i class="fa fa-home"></i></a>
        </form>
        <?php if ($message): ?>
            <div class="result"><?php echo $message; ?></div>
        <?php endif; ?>
    </div>

    <div id="loadingOverlay">
        <div class="loading-box">
            <div class="spinner"></div>
            <p style="margin:0; font-weight:bold; color:#333;">Processing...</p>
            <p style="margin:10px 0 0; font-size:13px; color:#999;">Please wait...</p>
        </div>
    </div>

    <script>
        // 显示选中文件名
        document.getElementById('fileToUpload').onchange = function() {
            if (this.files && this.files.length > 0) {
                document.getElementById('file-name-text').innerText = this.files[0].name;
            }
        };

        document.getElementById('convertForm').onsubmit = function() {
            // 验证是否已选文件
            if (document.getElementById('fileToUpload').files.length === 0) {
                alert("Please select a file first.");
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
