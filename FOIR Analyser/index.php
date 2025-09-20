<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>FOIR Analyser</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
    body {
        font-family: 'Inter', sans-serif;
        background: #f4f6f8;
        margin: 0;
        padding: 0;
        display: flex;
        justify-content: center;
        align-items: flex-start;
        min-height: 100vh;
        padding-top: 50px;
    }
    .container {
        width: 650px;
        background: #fff;
        border-radius: 12px;
        padding: 30px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.08);
        position: relative;
    }
    h2 {
        text-align: center;
        color: #333;
        margin-bottom: 25px;
        font-weight: 600;
    }
    #drop-area {
        border: 2px dashed #007BFF;
        padding: 35px;
        text-align: center;
        border-radius: 12px;
        cursor: pointer;
        transition: all 0.3s ease;
        font-size: 16px;
        color: #555;
    }
    #drop-area.hover { background: #e6f0ff; border-color: #0056b3; }
    #fileElem { display: none; }
    .file-preview { margin-top: 20px; }
    .file-item {
        background: #f9f9f9;
        padding: 12px 15px;
        border-radius: 10px;
        margin-bottom: 12px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.05);
        font-weight: 500;
        color: #333;
    }
    button {
        display: block;
        width: 100%;
        padding: 14px;
        font-size: 16px;
        background: #007BFF;
        color: #fff;
        border: none;
        border-radius: 10px;
        cursor: pointer;
        margin-top: 20px;
        font-weight: 500;
        transition: background 0.3s;
    }
    button:hover { background: #0056b3; }
    .result {
        margin-top: 25px;
        padding: 25px;
        border-radius: 12px;
        background: #f1f5f9;
        display: none;
    }
    .highlight { color: #28a745; font-weight: 600; }
    .error { color: #d9534f; font-weight: 600; }

    /* Full-page loader overlay */
    #overlay {
        position: fixed;
        top: 0; left: 0;
        width: 100vw;
        height: 100vh;
        background: rgba(0,0,0,0.4);
        display: none;
        justify-content: center;
        align-items: center;
        z-index: 9999;
        flex-direction: column;
        color: #fff;
        font-size: 18px;
        font-weight: 600;
    }
    .spinner {
        border: 6px solid #f3f3f3;
        border-top: 6px solid #007BFF;
        border-radius: 50%;
        width: 60px;
        height: 60px;
        animation: spin 1s linear infinite;
        margin-bottom: 15px;
    }
    @keyframes spin { 100% { transform: rotate(360deg); } }
</style>
</head>
<body>
<div class="container">
    <h2>FOIR Analyser</h2>

    <div id="drop-area">
        Drag & drop your documents here<br>or click to select files
        <input type="file" id="fileElem" multiple accept=".pdf,.png,.jpg,.jpeg">
    </div>

    <div class="file-preview" id="filePreview"></div>

    <button id="uploadBtn">Analyze</button>

    <div class="result" id="result"></div>
</div>

<!-- Full-page overlay -->
<div id="overlay">
    <div class="spinner"></div>
    Calculating FOIR, please wait...
</div>

<script>
const dropArea = document.getElementById('drop-area');
const fileInput = document.getElementById('fileElem');
const overlay = document.getElementById('overlay');
let filesToUpload = [];

// Drag & drop
dropArea.addEventListener('click', () => fileInput.click());
dropArea.addEventListener('dragover', e => { e.preventDefault(); dropArea.classList.add('hover'); });
dropArea.addEventListener('dragleave', () => dropArea.classList.remove('hover'));
dropArea.addEventListener('drop', e => {
    e.preventDefault();
    dropArea.classList.remove('hover');
    filesToUpload = Array.from(e.dataTransfer.files);
    renderFilePreview();
});

fileInput.addEventListener('change', e => {
    filesToUpload = Array.from(e.target.files);
    renderFilePreview();
});

function renderFilePreview() {
    const previewDiv = document.getElementById('filePreview');
    previewDiv.innerHTML = '';
    filesToUpload.forEach(file => {
        const fileItem = document.createElement('div');
        fileItem.className = 'file-item';
        fileItem.textContent = file.name;
        previewDiv.appendChild(fileItem);
    });
}

// Upload
document.getElementById('uploadBtn').addEventListener('click', function() {
    if (!filesToUpload.length) return alert('Please select at least one document.');

    const resultDiv = document.getElementById('result');
    resultDiv.style.display = 'none';
    overlay.style.display = 'flex'; // Show full-page loader

    const formData = new FormData();
    filesToUpload.forEach(file => formData.append('documents', file));

    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'http://127.0.0.1:8001/analyze', true);

// Convert explanation string into <ol> list if it contains bullet markers (*)
function formatExplanation(exp) {
    if (Array.isArray(exp)) {
        // Already structured as an array from backend
        return "<ol>" + exp.map(step => `<li>${step}</li>`).join('') + "</ol>";
    }
    if (typeof exp === "string" && exp.includes("*")) {
        // Split string on '*' markers, clean, and convert to <ol>
        const steps = exp.split("*")
                         .map(s => s.trim())
                         .filter(s => s.length > 0);
        return "<ol>" + steps.map(s => `<li>${s}</li>`).join('') + "</ol>";
    }
    // Fallback: just return wrapped in <p>
    return `<p>${exp}</p>`;
}

xhr.onload = function() {
    overlay.style.display = 'none'; 
    if (xhr.status === 200) {
        const data = JSON.parse(xhr.responseText);
        resultDiv.style.display = 'block';

        resultDiv.innerHTML = `
            <p><strong>Status:</strong> ${data.status}</p>
            <p><strong>Income:</strong> <span class="highlight">₹${data.income.toLocaleString()}</span></p>
            <p><strong>Obligations:</strong> <span class="highlight">₹${data.obligations.toLocaleString()}</span></p>
            <p><strong>FOIR:</strong> <span class="highlight">${data.foir.toFixed(2)}%</span></p>
            <p><strong>Eligible:</strong> ${data.eligible ? '✅ Yes' : '❌ No'}</p>
            <div>
                <strong>Explanation:</strong>
                ${formatExplanation(data.explanation)}
            </div>
        `;
    } else {
        let errMsg = 'Failed to fetch data from server.';
        try { errMsg = JSON.parse(xhr.responseText).error; } catch(e){}
        resultDiv.style.display = 'block';
        resultDiv.innerHTML = `<p class="error">Error: ${errMsg}</p>`;
    }
};


    xhr.onerror = function() {
        overlay.style.display = 'none';
        resultDiv.style.display = 'block';
        resultDiv.innerHTML = `<p class="error">Error: Network error or server unreachable</p>`;
    };

    xhr.send(formData);
});
</script>
</body>
</html>
