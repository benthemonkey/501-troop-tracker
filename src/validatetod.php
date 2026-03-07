<?php
require_once 'config.php';

if (!loggedIn()) {
    echo 'Please log in to troop tracker first.';
    return;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Trooper Event Import Tool</title>
    <style>
       body { font-family: 'Segoe UI', sans-serif; background: #f0f2f5; margin: 20px; color: #333; }
        .container { display: flex; gap: 20px; height: 85vh; }
        
        /* Left Side: Highlighter Logic */
        .editor-container { flex: 1; display: flex; flex-direction: column; position: relative; }
        #editor { 
            flex: 1; 
            background: transparent; 
            border: 1px solid #ced4da; 
            padding: 15px; 
            overflow-y: auto; 
            white-space: pre-wrap; 
            word-wrap: break-word;
            font-family: 'Consolas', monospace;
            outline: none;
            border-radius: 8px;
            z-index: 2;
            color: #333;
        }
        #highlighter {
            position: absolute;
            top: 28px; /* Offset for the label */
            left: 0;
            right: 0;
            bottom: 0;
            padding: 15px;
            white-space: pre-wrap;
            word-wrap: break-word;
            font-family: 'Consolas', monospace;
            color: transparent;
            pointer-events: none;
            z-index: 1;
            overflow-y: hidden;
            border: 1px solid transparent;
        }
        .error-line { background-color: #ffcccc; display: block; width: 100%; border-bottom: 1px solid #ff0000; }

        /* Right Side: Results */
        .preview-container { flex: 1.2; background: #fff; border: 1px solid #ced4da; padding: 20px; overflow-y: auto; border-radius: 8px; }
        .trooper-block { margin-bottom: 30px; border: 1px solid #e9ecef; border-radius: 6px; overflow: hidden; }
        .trooper-header { background: #212529; color: white; padding: 12px 15px; font-weight: bold; display: flex; justify-content: space-between; }
        
        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        th, td { border-bottom: 1px solid #dee2e6; padding: 10px 15px; text-align: left; }
        th { background: #f8f9fa; }
        
        .status-yes { color: #28a745; font-weight: bold; }
        .badge { padding: 3px 6px; border-radius: 4px; font-size: 0.7rem; font-weight: bold; }
        .badge-update { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        .badge-exact { background: #e7f3ff; color: #007bff; }
    </style>
</head>
<body>

<h2 style="margin-top: 0;">Midwest Garrison Event Parser <span style="font-size:14px">Use this to verify that the ToD text is formatted right and will get processed correctly.</span></h2>

<div class="container">
    <div class="editor-container">
        <div style="margin-bottom: 8px; font-weight: bold;">Paste Troop Log:</div>
        <div id="highlighter"></div>
        <div id="editor" contenteditable="true" spellcheck="false"></div>
    </div>

    <div class="preview-container" id="preview">
        <p style="color: #666; text-align: center; margin-top: 50px;">Waiting for trooper data...</p>
    </div>
</div>

<script>
const editor = document.getElementById('editor');
const highlighter = document.getElementById('highlighter');
const preview = document.getElementById('preview');

const trooperRegex = /^(\d+)\s+([A-Za-z\.\'\s]+?)(?:\s+([^\s]+@[^\s]+))?$/;
const eventRegex = /^\*\s+(\d{2}\/\d{2}\/\d{2})\s+(\w{2})\s+(.+?)(?:\s+\(([^)]+)\))?(?:\s+\[([^\]]+)\])?$/;

editor.addEventListener('input', () => {
    syncScroll();
    clearTimeout(window.parseTimeout);
    window.parseTimeout = setTimeout(processContent, 400);
});

editor.addEventListener('scroll', syncScroll);

function syncScroll() {
    highlighter.scrollTop = editor.scrollTop;
}

async function processContent() {
    const text = editor.innerText;
    const lines = text.split(/\r?\n/);
    
    let highlightHtml = '';
    let parsedData = [];
    let currentTrooper = null;

    lines.forEach(line => {
        const trimmed = line.trim();
        if (trimmed === "") {
            highlightHtml += '\n';
            return;
        }

        const isTrooper = trimmed.match(trooperRegex) && !trimmed.startsWith('*');
        const isEvent = trimmed.match(eventRegex);

        if (isTrooper) {
            const match = trimmed.match(trooperRegex);
            currentTrooper = { tkid: match[1], name: match[2], email: match[3] || 'N/A', events: [] };
            parsedData.push(currentTrooper);
            highlightHtml += line + '\n';
        } else if (isEvent && currentTrooper) {
            const match = trimmed.match(eventRegex);
            const dateParts = match[1].split('/');
            const exactCostume = match[5] ? match[5].trim() : null;
            
            currentTrooper.events.push({
                date: `20${dateParts[2]}-${dateParts[0]}-${dateParts[1]}`,
                name: match[3].trim(),
                costume: exactCostume || (match[4] ? match[4].trim() : ''),
                isExact: !!exactCostume
            });
            highlightHtml += line + '\n';
        } else {
            // Error highlighting
            highlightHtml += `<span class="error-line">${line}</span>\n`;
        }
    });

    highlighter.innerHTML = highlightHtml;

    if (parsedData.length > 0) {
        try {
            const response = await fetch('validatetodapi.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(parsedData)
            });
            const dbStatus = await response.json();
            renderPreview(parsedData, dbStatus);
        } catch (e) { console.error(e); }
    }
}

function renderPreview(data, status) {
    if (data.length === 0) {
        preview.innerHTML = '<p style="text-align:center; color:#999;">Waiting for valid trooper data...</p>';
        return;
    }

    let html = '';
    data.forEach(trooper => {
        const trooperStatus = status.find(s => s.tkid === trooper.tkid);
        
        html += `
        <div class="trooper-block">
            <div class="trooper-header">
                <span>${trooper.name} (TKID ${trooper.tkid})</span>
                <span style="font-size: 0.8rem; opacity: 0.8;">${trooper.email}</span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Event</th>
                        <th>Costume</th>
                        <th>Event Exists?</th>
                        <th>Cred</th>
                    </tr>
                </thead>
                <tbody>
                    ${trooper.events.map(ev => {
                        const s = trooperStatus ? trooperStatus.events.find(se => se.name === ev.name) : null;
                        
                        let costumeDisplay = '';
                        let credDisplay = s?.attended ? '<span class="status-yes">✔</span>' : '<span class="status-no">✘</span>';
                        
                        if (s) {
                            const isMismatch = s.attended && s.current_costume !== s.mapped_costume;
                            
                            if (isMismatch) {
                                // Highlight the change
                                costumeDisplay = `
                                    <div style="color: #856404; background: #fff3cd; padding: 4px; border-radius: 4px; border: 1px solid #ffeeba; font-size: 0.8rem;">
                                        <strong>Change Detected:</strong><br>
                                        <strike style="opacity: 0.6;">${s.current_costume}</strike> &rarr; <strong>${s.mapped_costume}</strong>
                                    </div>`;
                                credDisplay = '<span class="badge" style="background:#ffc107; color:#000;">UPDATE</span>';
                            } else {
                                costumeDisplay = `<span style="color: #495057;">${s.mapped_costume}</span>`;
                            }
                        }

                        return `
                        <tr>
                            <td style="white-space:nowrap; vertical-align: top;">${ev.date}</td>
                            <td style="vertical-align: top;">${ev.name}</td>
                            <td style="vertical-align: top;">
                                ${ev.isExact ? '<span class="badge badge-exact" style="margin-bottom:4px; display:inline-block;">Exact Match Used</span><br>' : ''}
                                ${costumeDisplay}
                            </td>
                            <td>${s?.exists ? '<span class="status-yes">✔</span>' : '<span class="badge badge-new">NEW</span>'}</td>
                            <td style="text-align:center; vertical-align: top;">${credDisplay}</td>
                        </tr>`;
                    }).join('')}
                </tbody>
            </table>
        </div>`;
    });
    preview.innerHTML = html;
}
</script>
</body>
</html>