<?php
session_start();
require 'bbcode.php'; 
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manual</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* --- CORE LAYOUT --- */
        body { 
            background: var(--bg-color, #0d0d0d); 
            margin: 0; 
            padding: 15px; 
            font-family: monospace; 
            color: #ccc;
            overflow-x: hidden; /* CRITICAL: No horizontal scroll */
        }

        /* --- THE ENCLOSURE (Wrapper) --- */
        .container {
            max-width: 700px; /* Enclosed width */
            margin: 0 auto;   /* Centered */
            padding-bottom: 50px;
        }

        /* --- STICKY HEADER --- */
        .manual-head {
            background: var(--bg-color, #0d0d0d);
            border-bottom: 2px solid var(--border-color, #333);
            padding-bottom: 10px;
            margin-bottom: 15px;
            position: sticky;
            top: 0;
            z-index: 100;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .head-title { 
            font-size: 0.9rem; 
            color: var(--accent-primary, #6a9c6a); 
            font-weight: bold; 
            letter-spacing: 2px;
            text-transform: uppercase;
        }
        .btn-close {
            background: #1a0505;
            border: 1px solid var(--accent-alert, #e06c75);
            color: var(--accent-alert, #e06c75);
            padding: 6px 15px;
            text-decoration: none;
            font-size: 0.75rem;
            font-weight: bold;
            display: inline-block;
        }
        .btn-close:hover {
            background: var(--accent-alert, #e06c75);
            color: #000;
        }

        /* --- SECTION HEADERS --- */
        .section-bar {
            background: #161616;
            color: #888;
            font-size: 0.7rem;
            padding: 5px 10px;
            margin: 20px 0 10px 0;
            border-left: 3px solid var(--accent-secondary, #d19a66);
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* --- STRICT 2-COLUMN GRID --- */
        .grid-2-col {
            display: grid;
            grid-template-columns: 1fr 1fr; /* FORCE 2 COLUMNS */
            gap: 10px;
            width: 100%;
        }

        /* --- THE MODULE BOX --- */
        .module {
            background: var(--panel-bg, #111);
            border: 1px solid var(--border-color, #333);
            padding: 8px 12px;
            box-sizing: border-box;
            
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: 42px; /* Uniform height */
            
            cursor: pointer;
            transition: all 0.1s;
            overflow: hidden; /* Safety clipping */
        }
        .module:hover {
            border-color: var(--accent-primary, #6a9c6a);
            background: #1a1a1a;
            transform: translateX(2px);
        }

        /* LEFT SIDE: THE CODE */
        .mod-code {
            font-family: monospace;
            font-size: 0.75rem;
            color: var(--text-main, #ccc);
            font-weight: bold;
            white-space: nowrap;
        }

        /* RIGHT SIDE: THE PREVIEW/DESC */
        .mod-desc {
            font-size: 0.65rem;
            color: #555;
            text-align: right;
            margin-left: 10px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 50%;
        }
        .module:hover .mod-desc { color: #888; }

        /* --- PREVIEW STYLES --- */
        .p-blur { filter: blur(2px); }
        .p-blur:hover { filter: none; }
        .p-glitch { color: #56b6c2; }
    </style>
</head>
<body>

<div class="container">

    <div class="manual-head">
        <span class="head-title">COMMAND REFERENCE</span>
        <a href="chat_stream.php" class="btn-close">CLOSE [X]</a>
    </div>

    <div class="section-bar">Security & Obfuscation</div>
    <div class="grid-2-col">
        <div class="module"><span class="mod-code">[pgp]...[/pgp]</span><span class="mod-desc" style="color:var(--accent-primary)">PGP BLOCK</span></div>
        <div class="module"><span class="mod-code">[redacted]..[/redacted]</span><span class="mod-desc bb-redacted">HIDDEN</span></div>
        <div class="module"><span class="mod-code">[spoiler]..[/spoiler]</span><span class="mod-desc bb-spoiler">HOVER</span></div>
        <div class="module"><span class="mod-code">[cmd]..[/cmd]</span><span class="mod-desc bb-cmd">TERM</span></div>
        <div class="module"><span class="mod-code">[warning]..[/warning]</span><span class="mod-desc" style="color:var(--accent-alert)">ALERT</span></div>
    </div>

    <div class="section-bar">Visual Effects</div>
    <div class="grid-2-col">
        <div class="module"><span class="mod-code">[glitch]..[/glitch]</span><span class="mod-desc p-glitch">GLITCH</span></div>
        <div class="module"><span class="mod-code">[shake]..[/shake]</span><span class="mod-desc anim-shake">SHAKE</span></div>
        <div class="module"><span class="mod-code">[blur]..[/blur]</span><span class="mod-desc p-blur">BLUR</span></div>
        <div class="module"><span class="mod-code">[ghost]..[/ghost]</span><span class="mod-desc bb-ghost">GHOST</span></div>
        <div class="module"><span class="mod-code">[pulse]..[/pulse]</span><span class="mod-desc anim-pulse">PULSE</span></div>
        <div class="module"><span class="mod-code">[glow]..[/glow]</span><span class="mod-desc text-glow">NEON</span></div>
        <div class="module"><span class="mod-code">[rainbow]..[/rainbow]</span><span class="mod-desc anim-rainbow">RGB</span></div>
    </div>

    <div class="section-bar">Transforms</div>
    <div class="grid-2-col">
        <div class="module"><span class="mod-code">[mirror]..[/mirror]</span><span class="mod-desc bb-mirror">MIRROR</span></div>
        <div class="module"><span class="mod-code">[flip]..[/flip]</span><span class="mod-desc bb-flip">FLIP</span></div>
        <div class="module"><span class="mod-code">[updown]..[/updown]</span><span class="mod-desc bb-upside">180°</span></div>
    </div>

    <div class="section-bar">Formatting</div>
    <div class="grid-2-col">
        <div class="module"><span class="mod-code">[b]..[/b]</span><span class="mod-desc"><strong>BOLD</strong></span></div>
        <div class="module"><span class="mod-code">[i]..[/i]</span><span class="mod-desc"><em>ITALIC</em></span></div>
        <div class="module"><span class="mod-code">[s]..[/s]</span><span class="mod-desc"><span class="bb-strike">STRIKE</span></span></div>
        <div class="module"><span class="mod-code">[u]..[/u]</span><span class="mod-desc"><u>UNDER</u></span></div>
        <div class="module"><span class="mod-code">[h1]..[/h1]</span><span class="mod-desc">HEAD</span></div>
        <div class="module"><span class="mod-code">[hr]</span><span class="mod-desc">LINE</span></div>
    </div>

    <div class="section-bar">Containers</div>
    <div class="grid-2-col">
        <div class="module"><span class="mod-code">[box=Ti]..[/box]</span><span class="mod-desc">BOX</span></div>
        <div class="module"><span class="mod-code">[quote]..[/quote]</span><span class="mod-desc">QUOTE</span></div>
        <div class="module"><span class="mod-code">[code]..[/code]</span><span class="mod-desc">CODE</span></div>
        <div class="module"><span class="mod-code">[ascii]..[/ascii]</span><span class="mod-desc">MONO</span></div>
    </div>

    <div class="section-bar">Colors</div>
    <div class="grid-2-col">
        <div class="module"><span class="mod-code">[color=red]..[/color]</span><span class="mod-desc" style="color:#e06c75">RED</span></div>
        <div class="module"><span class="mod-code">[color=blue]..[/color]</span><span class="mod-desc" style="color:#61afef">BLUE</span></div>
        <div class="module"><span class="mod-code">[color=green]..[/color]</span><span class="mod-desc" style="color:#98c379">GRN</span></div>
    </div>

    <div style="margin-top:20px; border-top:1px solid #222; padding-top:10px; text-align:center; color:#444; font-size:0.6rem;">
        // CLICK TO SELECT // COPY TO CLIPBOARD
    </div>
    <div class="section-bar">Interactive (v2)</div>
    <div class="grid-2-col">
        <div class="module"><span class="mod-code">[accordion title=X]..[/accordion]</span><span class="mod-desc">EXPAND</span></div>
        <div class="module"><span class="mod-code">[box=animated]..[/box]</span><span class="mod-desc">PULSE BOX</span></div>
        <div class="module"><span class="mod-code">[list=fancy]..[/list]</span><span class="mod-desc">TERM LIST</span></div>
    </div>

    <div class="section-bar">Dividers</div>
    <div class="grid-2-col">
        <div class="module"><span class="mod-code">[hr=glitch]</span><span class="mod-desc">GLITCH LINE</span></div>
        <div class="module"><span class="mod-code">[hr=neon]</span><span class="mod-desc">NEON LINE</span></div>
    </div>

</div> </body>
</html>