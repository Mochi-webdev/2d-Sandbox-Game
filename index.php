<!--
    2D Pixel Sandbox Engine (Firebase Edition)

    WHAT CHANGED IN THIS VERSION (read this before running it):

    1. ACCOUNTS ARE NOW REAL: Firebase Authentication (Email/Password, plus
       silent Anonymous sign-in for guests) replaces the old localStorage
       "fake login". You MUST fill in `firebaseConfig` below with your own
       Firebase project's config or none of this will work - the game will
       fall back to a local-only, no-multiplayer mode instead of crashing.

    2. ALL SAVES GO TO FIRESTORE: player profile (name/settings/skin),
       named skins, bookmarked servers, and saved worlds all live under
       `users/{uid}/...` in Firestore instead of localStorage. Worlds are
       split into a small "meta" doc (name/timestamp, cheap to list) and a
       separate "data" doc (the actual world arrays, only fetched when you
       load it).

    3. MULTIPLAYER NO LONGER USES PEERJS. PeerJS depends on a third-party
       hosted "PeerServer" broker (unpkg's peerjs.min.js talking to
       0.peerjs.com by default) to introduce two browsers to each other.
       That broker being flaky/blocked was the most likely reason
       "connecting" would work but the world would never arrive. This
       version does raw WebRTC (RTCPeerConnection + RTCDataChannel) and
       uses YOUR OWN Firestore project as the signaling channel instead -
       offers/answers/ICE candidates are exchanged via documents under
       `servers/{roomCode}/callers/{callerId}`. STUN+TURN servers are also
       included so connections survive strict NATs.

    4. HOSTED WORLDS BELONG TO THE HOST'S ACCOUNT: when you host, you pick
       "New World" or one of your saved worlds. While hosting, the world
       autosaves back to your account periodically, so next time you log
       in you can re-host the same world and previous players can rejoin
       the same place.

    5. THE SERVER BROWSER IS A REAL FIRESTORE QUERY (`servers` collection,
       `listed == true`), not a best-effort peer-to-peer relay hack.

    ==> See the REQUIRED SETUP notes near `firebaseConfig` below, and the
        Firestore security rules block further down - both must be done
        in your Firebase project for accounts/saves/multiplayer to work.
-->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>2D Pixel Sandbox Engine (Firebase Edition)</title>
    <link href="https://fonts.googleapis.com/css2?family=Press+Start+2P&display=swap" rel="stylesheet">
    <script src="https://www.gstatic.com/firebasejs/10.14.1/firebase-app-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/10.14.1/firebase-auth-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/10.14.1/firebase-firestore-compat.js"></script>
    <style>
        :root {
            --pixel-border-dark: #12121c;
            --pixel-border-light: #484c6e;
            --pixel-bg: #1e1e2e;
            --pixel-panel: #2a2a3d;
            --pixel-gold: #f39c12;
            --pixel-gold-bright: #f1c40f;
            --pixel-accent: #3498db;
            --pixel-accent-light: #5dade2;
            --pixel-green: #2ecc71;
            --pixel-red: #e74c3c;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; user-select: none; font-family: 'Press Start 2P', monospace; image-rendering: pixelated; }
        body, html { width: 100%; height: 100%; overflow: hidden; background-color: #0d0e15; color: #fff; }
        #game-container { position: relative; width: 100vw; height: 100vh; }
        canvas { display: block; width: 100%; height: 100%; image-rendering: pixelated; }
        #ui-layer { position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; display: flex; flex-direction: column; justify-content: space-between; padding: 20px; z-index: 10; }
        .hud-top { display: flex; justify-content: space-between; align-items: flex-start; pointer-events: auto; }
        .bottom-ui { display: flex; flex-direction: column; align-items: center; gap: 10px; pointer-events: auto; width: 100%; margin-bottom: 10px; }

        .pixel-panel {
            background: var(--pixel-panel); border: 4px solid var(--pixel-border-dark);
            box-shadow: inset -4px -4px 0px 0px #181824, inset 4px 4px 0px 0px var(--pixel-border-light), 0 8px 0px 0px rgba(0,0,0,0.6);
            padding: 12px; display: flex; gap: 10px; position: relative;
        }

        .pixel-btn {
            background: #27ae60; border: 3px solid #1e8449; box-shadow: inset -3px -3px 0 #145a32, inset 3px 3px 0 #2ecc71;
            color: #fff; padding: 8px 12px; font-size: 8px; cursor: pointer; text-transform: uppercase; text-shadow: 1px 1px 0 #000;
            display: inline-flex; align-items: center; justify-content: center; gap: 6px; transition: transform 0.05s ease;
        }
        .pixel-btn:hover { background: #2ecc71; transform: translateY(-2px); }
        .pixel-btn:active { transform: translateY(2px); box-shadow: inset 3px 3px 0 #145a32; }
        .pixel-btn.secondary { background: #2980b9; border-color: #1f618d; box-shadow: inset -3px -3px 0 #154360, inset 3px 3px 0 #5dade2; }
        .pixel-btn.secondary:hover { background: #3498db; }
        .pixel-btn.danger { background: #c0392b; border-color: #78281f; box-shadow: inset -3px -3px 0 #4a1511, inset 3px 3px 0 #e74c3c; }
        .pixel-btn.purple { background: #8e44ad; border-color: #5b2c6f; box-shadow: inset -3px -3px 0 #3f1f4d, inset 3px 3px 0 #a569bd; }
        .pixel-btn.small { padding: 5px 8px; font-size: 7px; }
        .pixel-btn:disabled { opacity: 0.5; cursor: default; transform: none !important; }

        .slot {
            position: relative; width: 48px; height: 48px; background: #121218; border: 3px solid #3a3a52;
            box-shadow: inset 3px 3px 0px #08080c, inset -1px -1px 0px #4a4a68;
            display: flex; justify-content: center; align-items: center; cursor: pointer; transition: background 0.1s;
        }
        .slot:hover { background: #1c1c28; border-color: var(--pixel-gold); }
        .slot.selected { border-color: var(--pixel-gold-bright); box-shadow: inset 3px 3px 0px #000, 0 0 12px var(--pixel-gold); background: #252318; }
        .slot-key { position: absolute; top: 3px; left: 4px; font-size: 8px; color: #7f8c8d; text-shadow: 1px 1px 0 #000; }
        .slot-count { position: absolute; bottom: 3px; right: 4px; font-size: 9px; color: #fff; text-shadow: 1px 1px 0 #000, -1px -1px 0 #000, 1px -1px 0 #000, -1px 1px 0 #000; }
        .item-icon-canvas { width: 32px; height: 32px; pointer-events: none; image-rendering: pixelated; }
        .durability-bar { position: absolute; bottom: 2px; left: 3px; width: calc(100% - 6px); height: 4px; background: #111; border: 1px solid #000; }
        .durability-fill { height: 100%; background: #2ecc71; }

        .health-row { display: flex; align-items: center; gap: 8px; }
        .health-label { font-size: 7px; color: var(--pixel-red); text-shadow: 1px 1px 0 #000; white-space: nowrap; }
        .health-bar-container { width: 240px; height: 14px; background: #111; border: 2px solid #000; box-shadow: inset 2px 2px 0 #000; overflow: hidden; }
        #health-bar-fill { height: 100%; width: 100%; background: linear-gradient(#e74c3c, #a93226); transition: width 0.25s ease; }

        #minimap-canvas { border: 3px solid var(--pixel-border-dark); box-shadow: inset -2px -2px 0px 0px #181824, inset 2px 2px 0px 0px var(--pixel-border-light), 0 4px 0 rgba(0,0,0,0.5); background: #0d0e15; width: 150px; height: 75px; }

        #crafting-backdrop { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.45); display: none; z-index: 55; pointer-events: auto; }
        #crafting-modal {
            position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); display: none; gap: 16px; pointer-events: auto; z-index: 60;
            background: var(--pixel-panel); border: 4px solid var(--pixel-border-dark);
            box-shadow: inset -4px -4px 0px 0px #181824, inset 4px 4px 0px 0px var(--pixel-border-light), 0 12px 24px rgba(0,0,0,0.8); padding: 16px;
        }
        .crafting-close-btn {
            position: absolute; top: -14px; right: -14px; width: 28px; height: 28px; background: var(--pixel-red); border: 3px solid #78281f;
            box-shadow: inset -2px -2px 0 #4a1511, inset 2px 2px 0 #e74c3c; color: #fff; font-size: 12px; display: flex; align-items: center; justify-content: center; cursor: pointer;
        }
        .crafting-container { display: flex; flex-direction: column; gap: 12px; align-items: center; }
        .crafting-grid { display: grid; grid-template-columns: repeat(3, 48px); gap: 6px; background: #161622; padding: 8px; border: 3px solid #28283a; }
        .crafting-arrow { font-size: 24px; color: var(--pixel-gold-bright); align-self: center; text-shadow: 2px 2px 0 #000; animation: pulse 1s infinite alternate; }
        @keyframes pulse { from { transform: scale(1); } to { transform: scale(1.15); } }
        .recipe-book-side { width: 240px; max-height: 240px; overflow-y: auto; display: flex; flex-direction: column; gap: 8px; background: #14141f; border: 3px solid #28283a; padding: 8px; }
        .recipe-book-side::-webkit-scrollbar { width: 8px; }
        .recipe-book-side::-webkit-scrollbar-track { background: #0d0e15; }
        .recipe-book-side::-webkit-scrollbar-thumb { background: #3a3a52; border: 1px solid #12121c; }
        .recipe-card { background: #1e1e2e; border: 2px solid #3a3a52; padding: 8px; font-size: 8px; color: #fff; cursor: pointer; display: flex; align-items: center; gap: 10px; transition: all 0.1s; }
        .recipe-card:hover { border-color: var(--pixel-gold-bright); background: #2a2a3d; transform: translateX(2px); }
        .recipe-card.locked { opacity: 0.5; filter: grayscale(0.5); }

        #dragged-item { position: fixed; width: 32px; height: 32px; pointer-events: none; z-index: 1000; display: none; transform: translate(-50%, -50%); }

        #menu-overlay {
            position: absolute; top: 0; left: 0; width: 100%; height: 100%;
            background: radial-gradient(circle, rgba(20,20,34,0.55) 0%, rgba(8,8,14,0.85) 100%);
            display: flex; flex-direction: column; justify-content: center; align-items: center; z-index: 100; backdrop-filter: blur(2px);
        }
        .menu-title-box { text-align: center; margin-bottom: 20px; }
        .menu-title { font-size: 24px; color: var(--pixel-gold-bright); text-shadow: 4px 4px 0px #000, -2px -2px 0px #000, 2px -2px 0px #000, -2px 2px 0px #000; letter-spacing: 2px; animation: floatText 3s ease-in-out infinite alternate; }
        @keyframes floatText { from { transform: translateY(0px); } to { transform: translateY(-8px); } }
        .menu-subtitle { font-size: 9px; color: #a0a0c0; margin-top: 8px; letter-spacing: 1px; text-shadow: 2px 2px 0 #000; }

        .menu-card {
            background: var(--pixel-panel); border: 4px solid var(--pixel-border-dark);
            box-shadow: inset -4px -4px 0px 0px #181824, inset 4px 4px 0px 0px var(--pixel-border-light), 0 16px 32px rgba(0,0,0,0.8);
            padding: 24px; display: none; flex-direction: column; gap: 12px; width: 460px; color: white; text-align: center;
        }
        input, select { padding: 10px; background: #121218; border: 3px solid #3a3a52; color: #fff; font-size: 8px; outline: none; box-shadow: inset 2px 2px 0 #000; width: 100%; }
        input:focus, select:focus { border-color: var(--pixel-gold-bright); }
        #host-code-box { background: #111; padding: 10px; border: 2px dashed var(--pixel-gold-bright); color: var(--pixel-gold-bright); font-size: 9px; word-break: break-all; margin-top: 6px; }
        .mp-row { display: flex; gap: 8px; }
        .mp-row .pixel-btn { flex: 1; }
        .mp-row input { flex: 2; }

        #notification { position: absolute; top: 24px; left: 50%; transform: translateX(-50%); background: var(--pixel-panel); border: 3px solid var(--pixel-gold-bright); box-shadow: 0 6px 0 #000; color: #fff; padding: 10px 20px; font-size: 8px; display: none; z-index: 200; text-align: center; max-width: 82vw; }
        .action-tip-box { background: rgba(0,0,0,0.6); border: 2px solid #3a3a52; padding: 6px 12px; color: #d0d0e0; font-size: 7px; text-shadow: 1px 1px 0 #000; }
        #player-count-display { display: none; align-items: center; border-color: var(--pixel-accent) !important; color: var(--pixel-accent-light); }

        .game-backdrop { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); display: none; z-index: 105; pointer-events: auto; }
        .game-modal {
            position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); display: none; flex-direction: column; gap: 14px; z-index: 110; pointer-events: auto;
            background: var(--pixel-panel); border: 4px solid var(--pixel-border-dark);
            box-shadow: inset -4px -4px 0 #181824, inset 4px 4px 0 var(--pixel-border-light), 0 12px 24px rgba(0,0,0,0.8);
            padding: 20px; max-width: 92vw; max-height: 88vh; overflow-y: auto; width: 520px;
        }
        .game-modal h3 { color: var(--pixel-gold-bright); font-size: 11px; text-shadow: 1px 1px 0 #000; text-align: center; margin-bottom: 4px; }
        .setting-row { display: flex; justify-content: space-between; align-items: center; font-size: 8px; padding: 10px; background: #161622; border: 2px solid #28283a; gap: 10px; }
        .setting-row input[type=checkbox] { width: 18px; height: 18px; cursor: pointer; flex: none; }
        .equip-column { display: flex; flex-direction: column; gap: 6px; align-items: center; }
        .equip-label { font-size: 6px; color: #a0a0c0; }
        .storage-grid { display: grid; grid-template-columns: repeat(6, 48px); gap: 6px; background: #161622; padding: 8px; border: 3px solid #28283a; }
        .skin-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; font-size: 8px; }
        .skin-row input[type=color] { width: 44px; height: 28px; border: 2px solid #000; background: none; cursor: pointer; padding: 0; }
        .saved-list-row { display: flex; gap: 6px; align-items: center; margin-bottom: 6px; }
        .saved-list-row .name { flex: 1; text-align: left; font-size: 7px; color: #fff; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        #skin-preview-canvas { background: #0d0e15; border: 3px solid var(--pixel-border-dark); image-rendering: pixelated; }

        #account-bar { display: flex; justify-content: space-between; align-items: center; font-size: 7px; color: var(--pixel-accent-light); padding-bottom: 8px; border-bottom: 2px solid #3a3a52; margin-bottom: 2px; gap: 8px; }
        .auth-hint { font-size: 6px; color: #888; line-height: 1.5; text-align: left; }
        .auth-error { font-size: 7px; color: var(--pixel-red); min-height: 10px; }
        .link-btn { background: none; border: none; color: var(--pixel-accent-light); font-size: 7px; text-decoration: underline; cursor: pointer; padding: 4px; }
    </style>
</head>
<body>

<div id="game-container">
    <div id="notification"></div>
    <canvas id="dragged-item" class="item-icon-canvas"></canvas>
    <canvas id="gameCanvas"></canvas>

    <div id="ui-layer" style="display: none;">
        <div class="hud-top">
            <div style="display:flex; gap:10px; align-items: flex-start; flex-wrap: wrap;">
                <button class="pixel-btn" onclick="toggleCraftingModal()">Crafting (C)</button>
                <button class="pixel-btn secondary" onclick="toggleInventoryModal()">Inventory (E)</button>
                <button class="pixel-btn purple" onclick="openWorldsModal()">Worlds</button>
                <button class="pixel-btn secondary" onclick="downloadWorldJSON()">Save JSON</button>
                <button class="pixel-btn danger" onclick="leaveToMenu()">Leave</button>
            </div>
            <div style="display:flex; flex-direction:column; align-items:flex-end; gap:6px;">
                <div style="display:flex; gap:8px;">
                    <div id="player-count-display" class="action-tip-box">PLAYERS: 1</div>
                    <div id="mode-display" class="action-tip-box" style="border-color: var(--pixel-gold);">SURVIVAL</div>
                </div>
                <canvas id="minimap-canvas" width="150" height="75"></canvas>
            </div>
        </div>

        <div id="crafting-backdrop" onclick="toggleCraftingModal(true)"></div>
        <div id="crafting-modal">
            <div class="crafting-close-btn" onclick="toggleCraftingModal(true)">X</div>
            <div class="crafting-container">
                <div style="color: var(--pixel-gold-bright); font-size: 9px; text-shadow: 1px 1px 0 #000;">3x3 CRAFTING GRID</div>
                <div style="display:flex; gap:16px; align-items:center;">
                    <div class="crafting-grid" id="crafting-grid"></div>
                    <div class="crafting-arrow">&#10148;</div>
                    <div class="slot" id="crafting-result" onclick="claimCraftResult()"></div>
                </div>
            </div>
            <div style="border-left: 3px solid #3a3a52; margin: 0 4px;"></div>
            <div>
                <div style="color: var(--pixel-gold-bright); font-size: 9px; text-align: center; margin-bottom: 8px; text-shadow: 1px 1px 0 #000;">RECIPE BOOK</div>
                <div class="recipe-book-side" id="recipe-book-list"></div>
            </div>
        </div>

        <div id="inventory-backdrop" class="game-backdrop" onclick="toggleInventoryModal(true)"></div>
        <div id="inventory-modal" class="game-modal">
            <h3>INVENTORY & EQUIPMENT</h3>
            <div style="display:flex; gap:16px; justify-content:center; align-items:flex-start; flex-wrap:wrap;">
                <div class="storage-grid" id="storage-grid"></div>
                <div class="equip-column">
                    <div class="equip-label">HEAD</div><div class="slot" id="equip-slot-head"></div>
                    <div class="equip-label">CHEST</div><div class="slot" id="equip-slot-chest"></div>
                    <div class="equip-label">LEGS</div><div class="slot" id="equip-slot-legs"></div>
                    <div class="equip-label">FEET</div><div class="slot" id="equip-slot-feet"></div>
                    <div id="defense-display" style="font-size:7px; color:var(--pixel-green); margin-top:6px;">Defense: 0%</div>
                </div>
            </div>
            <div style="font-size:7px; color:#a0a0c0; text-align:center;">Click a slot to pick an item up, click another slot to place it. Works with your hotbar too!</div>
            <button class="pixel-btn danger" onclick="toggleInventoryModal(true)">Close</button>
        </div>

        <div id="worlds-backdrop" class="game-backdrop" onclick="closeWorldsModal()"></div>
        <div id="worlds-modal" class="game-modal">
            <h3>MY SAVED WORLDS</h3>
            <div class="mp-row">
                <input type="text" id="world-save-name" placeholder="Name this save">
                <button class="pixel-btn small secondary" onclick="saveCurrentWorldToAccount()">Save As New</button>
            </div>
            <button class="pixel-btn small" id="world-save-current-btn" style="display:none;" onclick="saveCurrentWorldToAccount(activeCloudWorldId)">Update Current Save</button>
            <div id="worlds-modal-list" style="max-height:220px; overflow-y:auto;"></div>
            <div class="auth-hint">Saves live in your account (Firestore), not just this browser. Use "Save JSON" for a portable offline backup file.</div>
            <button class="pixel-btn danger" onclick="closeWorldsModal()">Close</button>
        </div>
    </div>

    <div id="settings-backdrop" class="game-backdrop" onclick="closeSettings()"></div>
    <div id="settings-modal" class="game-modal">
        <h3>SETTINGS</h3>
        <div class="setting-row"><span>Screen Shake</span><input type="checkbox" id="set-shake"></div>
        <div class="setting-row"><span>Dynamic Lighting (Day / Night)</span><input type="checkbox" id="set-lighting"></div>
        <div class="setting-row"><span>Ambient Cave Dust</span><input type="checkbox" id="set-dust"></div>
        <div class="setting-row"><span>Vignette</span><input type="checkbox" id="set-vignette"></div>
        <div class="setting-row"><span>Smooth Camera</span><input type="checkbox" id="set-smoothcam"></div>
        <button class="pixel-btn danger" onclick="closeSettings()">Close & Save</button>
    </div>

    <div id="skin-backdrop" class="game-backdrop" onclick="closeSkinEditor()"></div>
    <div id="skin-editor-modal" class="game-modal">
        <h3>SKIN EDITOR</h3>
        <div style="display:flex; gap:20px; align-items:flex-start; justify-content:center; flex-wrap:wrap;">
            <canvas id="skin-preview-canvas" width="96" height="140"></canvas>
            <div style="display:flex; flex-direction:column; gap:10px;">
                <div class="skin-row"><span>Skin Tone</span><input type="color" id="skin-color-skin"></div>
                <div class="skin-row"><span>Shirt</span><input type="color" id="skin-color-shirt"></div>
                <div class="skin-row"><span>Pants</span><input type="color" id="skin-color-pants"></div>
                <div class="skin-row"><span>Hair</span><input type="color" id="skin-color-hair"></div>
            </div>
        </div>
        <div class="mp-row">
            <input type="text" id="skin-save-name" placeholder="Name this skin">
            <button class="pixel-btn small secondary" onclick="saveNamedSkin()">Save As</button>
        </div>
        <div style="font-size:8px; color:var(--pixel-gold-bright); text-align:center;">SAVED SKINS</div>
        <div id="saved-skins-list" style="max-height:100px; overflow-y:auto;"></div>
        <button class="pixel-btn danger" onclick="closeSkinEditor()">Close & Save</button>
    </div>

    <div id="menu-overlay">
        <div class="menu-title-box">
            <div class="menu-title">PIXEL SANDBOX</div>
            <div class="menu-subtitle">ENGINE & WORLD BUILDER</div>
        </div>

        <div class="menu-card" id="auth-menu">
            <h3 style="color:var(--pixel-gold-bright); font-size: 10px; text-shadow: 1px 1px 0 #000;">SAVE YOUR PROGRESS</h3>
            <input type="text" id="auth-username" placeholder="Email">
            <input type="password" id="auth-password" placeholder="Password (6+ characters)">
            <div id="auth-error" class="auth-error"></div>
            <div style="display:flex; gap:8px;">
                <button class="pixel-btn" style="flex:1;" onclick="handleRegister()">Create Account</button>
                <button class="pixel-btn secondary" style="flex:1;" onclick="handleLogin()">Log Into Existing</button>
            </div>
            <button class="link-btn" onclick="handleForgotPassword()">Forgot password?</button>
            <button class="pixel-btn purple" onclick="showMenuSection('main-menu')">Back</button>
            <div class="auth-hint">"Create Account" turns your current guest profile into a permanent, cross-device account without losing your guest progress. "Log Into Existing" switches to a different, already-existing account and leaves this device's guest progress behind.</div>
        </div>

        <div class="menu-card" id="main-menu">
            <div id="account-bar">
                <span id="account-bar-label">Connecting...</span>
                <button id="account-bar-action" class="pixel-btn secondary small" onclick="showMenuSection('auth-menu')">Save Progress</button>
            </div>
            <input type="text" id="player-name" placeholder="Player Name" value="Explorer" onchange="persistPlayerName()">
            <button class="pixel-btn" onclick="showMenuSection('sp-menu')">Singleplayer</button>
            <button class="pixel-btn secondary" onclick="showMenuSection('mp-menu')">Multiplayer</button>
            <div style="display:flex; gap:8px;">
                <button class="pixel-btn purple small" style="flex:1;" onclick="openSkinEditor()">Skin Editor</button>
                <button class="pixel-btn secondary small" style="flex:1;" onclick="openSettings()">Settings</button>
            </div>
        </div>

        <div class="menu-card" id="sp-menu">
            <h3 style="color:var(--pixel-gold-bright); font-size: 10px; text-shadow: 1px 1px 0 #000;">SINGLEPLAYER WORLD</h3>
            <input type="text" id="world-seed" placeholder="World Seed (Optional)">
            <select id="game-mode">
                <option value="survival">Mode: Survival</option>
                <option value="creative">Mode: Creative (Infinite Range)</option>
            </select>
            <button class="pixel-btn" onclick="startSingleplayer()">Create New World</button>
            <input type="file" id="world-file-input" accept=".json" style="display:none" onchange="loadWorldFromFile(event)">
            <button class="pixel-btn secondary" onclick="document.getElementById('world-file-input').click()">Load World (.json)</button>
            <div style="font-size:8px; color:var(--pixel-gold-bright); margin-top:6px;">MY SAVED WORLDS (CLOUD)</div>
            <div id="account-worlds-list" style="max-height:110px; overflow-y:auto;"></div>
            <button class="pixel-btn danger" onclick="showMenuSection('main-menu')">Back</button>
        </div>

        <div class="menu-card" id="mp-menu">
            <h3 style="color:var(--pixel-accent-light); font-size: 10px; text-shadow: 1px 1px 0 #000;">MULTIPLAYER ROOMS</h3>
            <p style="font-size:7px; color:#a0a0c0; line-height: 1.4;">Host or join a live room, signaled through your Firebase project.</p>
            <select id="host-world-select"><option value="__new__">New World</option></select>
            <button class="pixel-btn" id="host-room-btn" onclick="hostMultiplayerRoom()">Host Server Room</button>
            <div class="setting-row"><span>List this room in Public Server Browser</span><input type="checkbox" id="host-list-public" checked></div>
            <div id="host-code-box" style="display:none;">GENERATING CODE...</div>
            <div class="mp-row">
                <button id="copy-code-btn" class="pixel-btn secondary small" style="display:none;" onclick="copyRoomCode()">Copy Code</button>
                <button id="start-hosted-btn" class="pixel-btn small" style="display:none;" onclick="startEngineUI()">Start World</button>
            </div>
            <hr style="border-color: #3a3a52; margin: 4px 0; width:100%;">
            <input type="text" id="join-room-code" placeholder="Enter Host Code" style="text-transform:uppercase;">
            <button class="pixel-btn secondary" id="join-room-btn" onclick="joinMultiplayerRoom()">Join Room</button>
            <hr style="border-color: #3a3a52; margin: 4px 0; width:100%;">
            <div style="font-size:8px; color:var(--pixel-accent-light); display:flex; justify-content:space-between; align-items:center;">
                <span>PUBLIC SERVERS RIGHT NOW</span>
            </div>
            <div id="server-browser-list" style="max-height:90px; overflow-y:auto;"></div>
            <hr style="border-color: #3a3a52; margin: 4px 0; width:100%;">
            <div style="font-size:8px; color:var(--pixel-gold-bright);">MY BOOKMARKED SERVERS</div>
            <div id="saved-servers-list" style="max-height:100px; overflow-y:auto;"></div>
            <div class="mp-row">
                <input type="text" id="server-save-name" placeholder="Name to bookmark current code">
                <button class="pixel-btn small secondary" onclick="saveCurrentServerCode()">Save</button>
            </div>
            <button class="pixel-btn danger" onclick="showMenuSection('main-menu')">Back</button>
        </div>
    </div>
</div>

<script>
const canvas = document.getElementById('gameCanvas');
const ctx = canvas.getContext('2d');

const TILE_SIZE = 32;
const WORLD_WIDTH = 200;
const WORLD_HEIGHT = 100;
const INTERACTION_RANGE = 4.5 * TILE_SIZE;
const HAND_POWER = 1.0;

const ITEM_TYPES = { BLOCK: 0, TOOL: 1, MATERIAL: 2, ARMOR: 3 };

const BLOCKS = {
    AIR: 0, DIRT: 1, GRASS: 2, STONE: 3, WOOD: 4,
    LEAVES: 5, PLANK: 6, COAL: 7, IRON: 8, GOLD: 9,
    WOOD_PICKAXE: 10, STONE_PICKAXE: 11,
    SAND: 12, WATER: 13, TORCH: 14,
    IRON_INGOT: 15, GOLD_INGOT: 16,
    IRON_PICKAXE: 17, GOLD_PICKAXE: 18,
    CRAFTING_BENCH: 19, FURNACE: 20, DEEP_STONE: 21,
    IRON_HELMET: 22, IRON_CHEST: 23, IRON_LEGS: 24, IRON_BOOTS: 25,
    GOLD_HELMET: 26, GOLD_CHEST: 27, GOLD_LEGS: 28, GOLD_BOOTS: 29
};

const BLOCK_PROPS = {
    [BLOCKS.AIR]: { solid: false, color: 'transparent', hardness: 0 },
    [BLOCKS.DIRT]: { solid: true, color: '#795548', hardness: 1.0, itemType: ITEM_TYPES.BLOCK, name: "Dirt" },
    [BLOCKS.GRASS]: { solid: true, color: '#4CAF50', hardness: 1.0, itemType: ITEM_TYPES.BLOCK, name: "Grass Block" },
    [BLOCKS.STONE]: { solid: true, color: '#607D8B', hardness: 3.5, itemType: ITEM_TYPES.BLOCK, name: "Stone", minPower: 2 },
    [BLOCKS.WOOD]: { solid: true, color: '#8D6E63', hardness: 2.0, itemType: ITEM_TYPES.BLOCK, name: "Wood Log" },
    [BLOCKS.LEAVES]: { solid: false, color: '#2E7D32', hardness: 0.2, itemType: ITEM_TYPES.BLOCK, name: "Leaves" },
    [BLOCKS.PLANK]: { solid: true, color: '#D7CCC8', hardness: 1.5, itemType: ITEM_TYPES.BLOCK, name: "Wooden Plank" },
    [BLOCKS.COAL]: { solid: true, color: '#212121', hardness: 4.0, itemType: ITEM_TYPES.BLOCK, name: "Coal Ore", minPower: 2 },
    [BLOCKS.IRON]: { solid: true, color: '#B0BEC5', hardness: 6.0, itemType: ITEM_TYPES.BLOCK, name: "Iron Ore", minPower: 4 },
    [BLOCKS.GOLD]: { solid: true, color: '#FFD700', hardness: 8.0, itemType: ITEM_TYPES.BLOCK, name: "Gold Ore", minPower: 7 },
    [BLOCKS.WOOD_PICKAXE]: { solid: false, color: '#A1887F', itemType: ITEM_TYPES.TOOL, power: 2.5, maxDurability: 30, name: "Wooden Pickaxe" },
    [BLOCKS.STONE_PICKAXE]: { solid: false, color: '#78909C', itemType: ITEM_TYPES.TOOL, power: 5.0, maxDurability: 60, name: "Stone Pickaxe" },
    [BLOCKS.SAND]: { solid: true, color: '#E0C080', hardness: 0.8, itemType: ITEM_TYPES.BLOCK, name: "Sand" },
    [BLOCKS.WATER]: { solid: false, color: 'rgba(52,173,226,0.6)', hardness: 0, itemType: ITEM_TYPES.BLOCK, name: "Water", unbreakable: true },
    [BLOCKS.TORCH]: { solid: false, color: '#f39c12', hardness: 0.2, itemType: ITEM_TYPES.BLOCK, name: "Torch", light: true, lightRadius: 4.5 },
    [BLOCKS.IRON_INGOT]: { solid: false, color: '#d7d7d7', itemType: ITEM_TYPES.MATERIAL, name: "Iron Ingot" },
    [BLOCKS.GOLD_INGOT]: { solid: false, color: '#ffd700', itemType: ITEM_TYPES.MATERIAL, name: "Gold Ingot" },
    [BLOCKS.IRON_PICKAXE]: { solid: false, color: '#d7d7d7', itemType: ITEM_TYPES.TOOL, power: 8.0, maxDurability: 100, name: "Iron Pickaxe" },
    [BLOCKS.GOLD_PICKAXE]: { solid: false, color: '#ffd700', itemType: ITEM_TYPES.TOOL, power: 12.0, maxDurability: 40, name: "Gold Pickaxe" },
    [BLOCKS.CRAFTING_BENCH]: { solid: true, color: '#8a5a3c', hardness: 2.0, itemType: ITEM_TYPES.BLOCK, name: "Crafting Bench" },
    [BLOCKS.FURNACE]: { solid: true, color: '#5b5b5b', hardness: 3.0, itemType: ITEM_TYPES.BLOCK, name: "Furnace", light: true, lightRadius: 4.0 },
    [BLOCKS.DEEP_STONE]: { solid: true, color: '#37474F', hardness: 5.0, itemType: ITEM_TYPES.BLOCK, name: "Deepstone", minPower: 4 },
    [BLOCKS.IRON_HELMET]: { solid: false, color: '#cfd8dc', itemType: ITEM_TYPES.ARMOR, slot: 'head', defense: 4, name: "Iron Helmet" },
    [BLOCKS.IRON_CHEST]: { solid: false, color: '#cfd8dc', itemType: ITEM_TYPES.ARMOR, slot: 'chest', defense: 6, name: "Iron Chestplate" },
    [BLOCKS.IRON_LEGS]: { solid: false, color: '#cfd8dc', itemType: ITEM_TYPES.ARMOR, slot: 'legs', defense: 5, name: "Iron Leggings" },
    [BLOCKS.IRON_BOOTS]: { solid: false, color: '#cfd8dc', itemType: ITEM_TYPES.ARMOR, slot: 'feet', defense: 3, name: "Iron Boots" },
    [BLOCKS.GOLD_HELMET]: { solid: false, color: '#ffd700', itemType: ITEM_TYPES.ARMOR, slot: 'head', defense: 5, name: "Gold Helmet" },
    [BLOCKS.GOLD_CHEST]: { solid: false, color: '#ffd700', itemType: ITEM_TYPES.ARMOR, slot: 'chest', defense: 8, name: "Gold Chestplate" },
    [BLOCKS.GOLD_LEGS]: { solid: false, color: '#ffd700', itemType: ITEM_TYPES.ARMOR, slot: 'legs', defense: 7, name: "Gold Leggings" },
    [BLOCKS.GOLD_BOOTS]: { solid: false, color: '#ffd700', itemType: ITEM_TYPES.ARMOR, slot: 'feet', defense: 4, name: "Gold Boots" }
};

const RECIPES = [
    { name: "Wood Planks", station: null, result: { type: BLOCKS.PLANK, count: 4 },
      grid: [ null, null, null, null, BLOCKS.WOOD, null, null, null, null ] },
    { name: "Crafting Bench", station: null, result: { type: BLOCKS.CRAFTING_BENCH, count: 1 },
      grid: [ BLOCKS.PLANK, BLOCKS.PLANK, null, BLOCKS.PLANK, BLOCKS.PLANK, null, null, null, null ] },
    { name: "Torch", station: null, result: { type: BLOCKS.TORCH, count: 4 },
      grid: [ null, null, null, null, BLOCKS.COAL, null, null, BLOCKS.WOOD, null ] },
    { name: "Wooden Pickaxe", station: 'bench', result: { type: BLOCKS.WOOD_PICKAXE, count: 1, durability: 30 },
      grid: [ BLOCKS.PLANK, BLOCKS.PLANK, BLOCKS.PLANK, null, BLOCKS.WOOD, null, null, BLOCKS.WOOD, null ] },
    { name: "Furnace", station: 'bench', result: { type: BLOCKS.FURNACE, count: 1 },
      grid: [ BLOCKS.STONE, BLOCKS.STONE, BLOCKS.STONE, BLOCKS.STONE, null, BLOCKS.STONE, BLOCKS.STONE, BLOCKS.STONE, BLOCKS.STONE ] },
    { name: "Stone Pickaxe", station: 'bench', result: { type: BLOCKS.STONE_PICKAXE, count: 1, durability: 60 },
      grid: [ BLOCKS.STONE, BLOCKS.STONE, BLOCKS.STONE, null, BLOCKS.WOOD, null, null, BLOCKS.WOOD, null ] },
    { name: "Smelt Iron Ingot", station: 'furnace', result: { type: BLOCKS.IRON_INGOT, count: 1 },
      grid: [ null, null, null, null, BLOCKS.IRON, null, null, BLOCKS.COAL, null ] },
    { name: "Smelt Gold Ingot", station: 'furnace', result: { type: BLOCKS.GOLD_INGOT, count: 1 },
      grid: [ null, null, null, null, BLOCKS.GOLD, null, null, BLOCKS.COAL, null ] },
    { name: "Iron Pickaxe", station: 'bench', result: { type: BLOCKS.IRON_PICKAXE, count: 1, durability: 100 },
      grid: [ BLOCKS.IRON_INGOT, BLOCKS.IRON_INGOT, BLOCKS.IRON_INGOT, null, BLOCKS.WOOD, null, null, BLOCKS.WOOD, null ] },
    { name: "Gold Pickaxe", station: 'bench', result: { type: BLOCKS.GOLD_PICKAXE, count: 1, durability: 40 },
      grid: [ BLOCKS.GOLD_INGOT, BLOCKS.GOLD_INGOT, BLOCKS.GOLD_INGOT, null, BLOCKS.WOOD, null, null, BLOCKS.WOOD, null ] },
    { name: "Iron Helmet", station: 'bench', result: { type: BLOCKS.IRON_HELMET, count: 1 },
      grid: [ BLOCKS.IRON_INGOT, BLOCKS.IRON_INGOT, BLOCKS.IRON_INGOT, BLOCKS.IRON_INGOT, null, BLOCKS.IRON_INGOT, null, null, null ] },
    { name: "Iron Chestplate", station: 'bench', result: { type: BLOCKS.IRON_CHEST, count: 1 },
      grid: [ BLOCKS.IRON_INGOT, null, BLOCKS.IRON_INGOT, BLOCKS.IRON_INGOT, BLOCKS.IRON_INGOT, BLOCKS.IRON_INGOT, BLOCKS.IRON_INGOT, BLOCKS.IRON_INGOT, BLOCKS.IRON_INGOT ] },
    { name: "Iron Leggings", station: 'bench', result: { type: BLOCKS.IRON_LEGS, count: 1 },
      grid: [ BLOCKS.IRON_INGOT, BLOCKS.IRON_INGOT, BLOCKS.IRON_INGOT, BLOCKS.IRON_INGOT, null, BLOCKS.IRON_INGOT, BLOCKS.IRON_INGOT, null, BLOCKS.IRON_INGOT ] },
    { name: "Iron Boots", station: 'bench', result: { type: BLOCKS.IRON_BOOTS, count: 1 },
      grid: [ null, null, null, BLOCKS.IRON_INGOT, null, BLOCKS.IRON_INGOT, BLOCKS.IRON_INGOT, null, BLOCKS.IRON_INGOT ] },
    { name: "Gold Helmet", station: 'bench', result: { type: BLOCKS.GOLD_HELMET, count: 1 },
      grid: [ BLOCKS.GOLD_INGOT, BLOCKS.GOLD_INGOT, BLOCKS.GOLD_INGOT, BLOCKS.GOLD_INGOT, null, BLOCKS.GOLD_INGOT, null, null, null ] },
    { name: "Gold Chestplate", station: 'bench', result: { type: BLOCKS.GOLD_CHEST, count: 1 },
      grid: [ BLOCKS.GOLD_INGOT, null, BLOCKS.GOLD_INGOT, BLOCKS.GOLD_INGOT, BLOCKS.GOLD_INGOT, BLOCKS.GOLD_INGOT, BLOCKS.GOLD_INGOT, BLOCKS.GOLD_INGOT, BLOCKS.GOLD_INGOT ] },
    { name: "Gold Leggings", station: 'bench', result: { type: BLOCKS.GOLD_LEGS, count: 1 },
      grid: [ BLOCKS.GOLD_INGOT, BLOCKS.GOLD_INGOT, BLOCKS.GOLD_INGOT, BLOCKS.GOLD_INGOT, null, BLOCKS.GOLD_INGOT, BLOCKS.GOLD_INGOT, null, BLOCKS.GOLD_INGOT ] },
    { name: "Gold Boots", station: 'bench', result: { type: BLOCKS.GOLD_BOOTS, count: 1 },
      grid: [ null, null, null, BLOCKS.GOLD_INGOT, null, BLOCKS.GOLD_INGOT, BLOCKS.GOLD_INGOT, null, BLOCKS.GOLD_INGOT ] }
];


const firebaseConfig = {
  apiKey: "AIzaSyAcQFP_pklDrcs57iXbQAt3DZtdZyXLAKs",
  authDomain: "d-engine-sandbox.firebaseapp.com",
  projectId: "d-engine-sandbox",
  storageBucket: "d-engine-sandbox.firebasestorage.app",
  messagingSenderId: "859105469634",
  appId: "1:859105469634:web:e7549aaf7e5ea9d718c75b"
};


let firebaseApp = null, auth = null, db = null, firebaseReady = false;
try {
    if (firebaseConfig.apiKey && firebaseConfig.apiKey !== 'YOUR_API_KEY') {
        firebaseApp = firebase.initializeApp(firebaseConfig);
        auth = firebase.auth();
        db = firebase.firestore();
        firebaseReady = true;
    }
} catch (e) { console.error('Firebase init failed:', e); firebaseReady = false; }

function defaultSettingsObj() { return { shake: true, lighting: true, dust: true, vignette: true, smoothCamera: true }; }
function defaultSkinObj() { return { skin: '#ffcc99', shirt: '#e74c3c', pants: '#2c3e50', hair: '#3b2412' }; }

let currentUser = null; // firebase.User, or null if firebase isn't configured
let profile = { playerName: 'Explorer', settings: defaultSettingsObj(), activeSkin: defaultSkinObj() };
let skinsCache = [];
let serversCache = [];
let worldsMetaCache = [];
let activeCloudWorldId = null;

function userDocRef() { return db.collection('users').doc(currentUser.uid); }
function skinsColRef() { return userDocRef().collection('skins'); }
function serversColRef() { return userDocRef().collection('servers'); }
function worldsMetaColRef() { return userDocRef().collection('worldsMeta'); }
function worldsDataColRef() { return userDocRef().collection('worldsData'); }

function requireOnline() {
    if (!firebaseReady) { notify('This needs Firebase configured - see the setup comment near the top of the file.'); return false; }
    if (!currentUser) { notify('Still connecting to your account - try again in a moment.'); return false; }
    return true;
}

async function loadCloudProfile() {
    const snap = await userDocRef().get();
    if (snap.exists) {
        const d = snap.data();
        profile.playerName = d.playerName || 'Explorer';
        profile.settings = { ...defaultSettingsObj(), ...(d.settings || {}) };
        profile.activeSkin = { ...defaultSkinObj(), ...(d.activeSkin || {}) };
    } else {
        profile = { playerName: 'Explorer', settings: defaultSettingsObj(), activeSkin: defaultSkinObj() };
        await userDocRef().set({ playerName: profile.playerName, settings: profile.settings, activeSkin: profile.activeSkin, createdAt: firebase.firestore.FieldValue.serverTimestamp() }, { merge: true });
    }
    settings = profile.settings;
    currentSkin = profile.activeSkin;
    const [skinsSnap, serversSnap, worldsSnap] = await Promise.all([
        skinsColRef().get(), serversColRef().get(), worldsMetaColRef().get()
    ]);
    skinsCache = skinsSnap.docs.map(d => ({ id: d.id, ...d.data() }));
    serversCache = serversSnap.docs.map(d => ({ id: d.id, ...d.data() }));
    worldsMetaCache = worldsSnap.docs.map(d => ({ id: d.id, ...d.data() })).sort((a, b) => (b.ts || 0) - (a.ts || 0));
}

function saveProfileField(patch) {
    Object.assign(profile, patch);
    if (!firebaseReady || !currentUser) return;
    userDocRef().set(patch, { merge: true }).catch(e => notify('Cloud save failed: ' + e.message));
}

function persistPlayerName() {
    profile.playerName = document.getElementById('player-name').value;
    saveProfileField({ playerName: profile.playerName });
}

function refreshAccountBar() {
    const label = document.getElementById('account-bar-label');
    const action = document.getElementById('account-bar-action');
    if (!firebaseReady) { label.innerText = 'Offline mode - Firebase not configured'; action.style.display = 'none'; return; }
    if (!currentUser) { label.innerText = 'Connecting...'; return; }
    action.style.display = 'inline-flex';
    if (currentUser.isAnonymous) {
        label.innerText = 'Playing as Guest';
        action.innerText = 'Save Progress Permanently';
        action.onclick = () => showMenuSection('auth-menu');
    } else {
        label.innerText = 'Signed in: ' + currentUser.email;
        action.innerText = 'Log Out';
        action.onclick = handleLogout;
    }
}

async function handleRegister() {
    const email = document.getElementById('auth-username').value.trim();
    const pass = document.getElementById('auth-password').value;
    const errEl = document.getElementById('auth-error');
    errEl.innerText = '';
    if (!email || !pass) { errEl.innerText = 'Enter an email and password.'; return; }
    try {
        if (currentUser && currentUser.isAnonymous) {
            const cred = firebase.auth.EmailAuthProvider.credential(email, pass);
            await currentUser.linkWithCredential(cred);
            notify('Account created - your guest progress is now saved permanently!');
        } else {
            await auth.createUserWithEmailAndPassword(email, pass);
        }
        showMenuSection('main-menu');
    } catch (e) { errEl.innerText = e.message; }
}
async function handleLogin() {
    const email = document.getElementById('auth-username').value.trim();
    const pass = document.getElementById('auth-password').value;
    const errEl = document.getElementById('auth-error');
    errEl.innerText = '';
    if (!email || !pass) { errEl.innerText = 'Enter an email and password.'; return; }
    if (currentUser && currentUser.isAnonymous) {
        const proceed = confirm('Logging into an existing account leaves this device\'s guest progress behind. Continue?');
        if (!proceed) return;
    }
    try {
        await auth.signOut();
        await auth.signInWithEmailAndPassword(email, pass);
        showMenuSection('main-menu');
    } catch (e) { errEl.innerText = e.message; }
}
async function handleForgotPassword() {
    const email = document.getElementById('auth-username').value.trim();
    if (!email) return notify('Enter your email above first.');
    try { await auth.sendPasswordResetEmail(email); notify('Password reset email sent.'); }
    catch (e) { notify(e.message); }
}
async function handleLogout() {
    try { await auth.signOut(); notify('Signed out - starting a fresh guest profile on this device.'); }
    catch (e) { notify(e.message); }
}

if (firebaseReady) {
    auth.onAuthStateChanged(async user => {
        if (!user) { auth.signInAnonymously().catch(e => notify('Sign-in failed: ' + e.message)); return; }
        currentUser = user;
        stopServerBrowserListener();
        try { await loadCloudProfile(); } catch (e) { notify('Could not load your profile: ' + e.message); }
        refreshAccountBar();
        document.getElementById('player-name').value = profile.playerName || 'Explorer';
        renderAccountWorldsList();
        if (document.getElementById('ui-layer').style.display === 'none') showMenuSection('main-menu');
    });
} else {
    document.addEventListener('DOMContentLoaded', () => { refreshAccountBar(); showMenuSection('main-menu'); });
}

// SETTINGS
let settings = defaultSettingsObj();
function loadSettings() { settings = { ...defaultSettingsObj(), ...(profile.settings || {}) }; }
function saveSettings() { profile.settings = settings; saveProfileField({ settings }); }

function openSettings() {
    document.getElementById('set-shake').checked = settings.shake;
    document.getElementById('set-lighting').checked = settings.lighting;
    document.getElementById('set-dust').checked = settings.dust;
    document.getElementById('set-vignette').checked = settings.vignette;
    document.getElementById('set-smoothcam').checked = settings.smoothCamera;
    document.getElementById('settings-modal').style.display = 'flex';
    document.getElementById('settings-backdrop').style.display = 'block';
}
function closeSettings() {
    settings.shake = document.getElementById('set-shake').checked;
    settings.lighting = document.getElementById('set-lighting').checked;
    settings.dust = document.getElementById('set-dust').checked;
    settings.vignette = document.getElementById('set-vignette').checked;
    settings.smoothCamera = document.getElementById('set-smoothcam').checked;
    saveSettings();
    document.getElementById('settings-modal').style.display = 'none';
    document.getElementById('settings-backdrop').style.display = 'none';
}

// SKIN
let currentSkin = defaultSkinObj();
function loadSkin() { currentSkin = { ...defaultSkinObj(), ...(profile.activeSkin || {}) }; }
function saveActiveSkin() { profile.activeSkin = currentSkin; saveProfileField({ activeSkin: currentSkin }); }

function getSavedSkins() { return skinsCache; }
async function saveNamedSkin() {
    const name = document.getElementById('skin-save-name').value.trim();
    if (!name) return notify('Give your skin a name!');
    if (!requireOnline()) return;
    try {
        const ref = await skinsColRef().add({ name, skin: { ...currentSkin }, ts: Date.now() });
        skinsCache.push({ id: ref.id, name, skin: { ...currentSkin } });
        document.getElementById('skin-save-name').value = '';
        renderSavedSkinsList();
        notify('Skin saved to your account!');
    } catch (e) { notify('Could not save skin: ' + e.message); }
}

function openSkinEditor() {
    document.getElementById('skin-color-skin').value = currentSkin.skin;
    document.getElementById('skin-color-shirt').value = currentSkin.shirt;
    document.getElementById('skin-color-pants').value = currentSkin.pants;
    document.getElementById('skin-color-hair').value = currentSkin.hair;
    ['skin', 'shirt', 'pants', 'hair'].forEach(k => {
        document.getElementById('skin-color-' + k).oninput = (e) => { currentSkin[k] = e.target.value; drawSkinPreview(); };
    });
    drawSkinPreview();
    renderSavedSkinsList();
    document.getElementById('skin-editor-modal').style.display = 'flex';
    document.getElementById('skin-backdrop').style.display = 'block';
}
function closeSkinEditor() {
    saveActiveSkin();
    document.getElementById('skin-editor-modal').style.display = 'none';
    document.getElementById('skin-backdrop').style.display = 'none';
}
function drawSkinPreview() {
    const cvs = document.getElementById('skin-preview-canvas');
    const c = cvs.getContext('2d');
    c.clearRect(0, 0, cvs.width, cvs.height);
    c.fillStyle = '#1c1c28';
    c.fillRect(0, 0, cvs.width, cvs.height);
    const sx = 30, sy = 10;
    c.fillStyle = currentSkin.shirt;
    c.fillRect(sx + 2, sy + 22, 32, 34);
    c.fillStyle = '#2c3e50';
    c.fillRect(sx + 3, sy + 56, 12, 28);
    c.fillRect(sx + 21, sy + 56, 12, 28);
    c.fillStyle = currentSkin.pants;
    c.fillRect(sx + 3, sy + 56, 12, 20);
    c.fillRect(sx + 21, sy + 56, 12, 20);
    c.fillStyle = currentSkin.skin;
    c.fillRect(sx, sy, 36, 22);
    c.fillStyle = currentSkin.hair;
    c.fillRect(sx - 1, sy - 4, 38, 8);
    c.fillStyle = '#000';
    c.fillRect(sx + 8, sy + 8, 4, 4);
    c.fillRect(sx + 24, sy + 8, 4, 4);
}
function renderSavedSkinsList() {
    const listEl = document.getElementById('saved-skins-list');
    listEl.innerHTML = '';
    const list = getSavedSkins();
    if (!firebaseReady) { listEl.innerHTML = '<div style="font-size:7px;color:#888;">Saved skins need Firebase configured.</div>'; return; }
    if (list.length === 0) { listEl.innerHTML = '<div style="font-size:7px;color:#888;">No saved skins yet.</div>'; return; }
    list.forEach((entry) => {
        const row = document.createElement('div');
        row.className = 'saved-list-row';
        const label = document.createElement('div');
        label.className = 'name';
        label.innerText = entry.name;
        row.appendChild(label);
        const useBtn = document.createElement('button');
        useBtn.className = 'pixel-btn small';
        useBtn.innerText = 'Use';
        useBtn.onclick = () => {
            currentSkin = { ...entry.skin };
            saveActiveSkin();
            document.getElementById('skin-color-skin').value = currentSkin.skin;
            document.getElementById('skin-color-shirt').value = currentSkin.shirt;
            document.getElementById('skin-color-pants').value = currentSkin.pants;
            document.getElementById('skin-color-hair').value = currentSkin.hair;
            drawSkinPreview();
            notify('Skin applied!');
        };
        row.appendChild(useBtn);
        const delBtn = document.createElement('button');
        delBtn.className = 'pixel-btn danger small';
        delBtn.innerText = 'X';
        delBtn.onclick = async () => {
            try { await skinsColRef().doc(entry.id).delete(); skinsCache = skinsCache.filter(s => s.id !== entry.id); renderSavedSkinsList(); }
            catch (e) { notify('Could not delete: ' + e.message); }
        };
        row.appendChild(delBtn);
        listEl.appendChild(row);
    });
}

// SAVED (BOOKMARKED) SERVERS
function getSavedServers() { return serversCache; }
function renderServerList() {
    const listEl = document.getElementById('saved-servers-list');
    if (!listEl) return;
    listEl.innerHTML = '';
    if (!firebaseReady) { listEl.innerHTML = '<div style="font-size:7px;color:#888;">Bookmarks need Firebase configured.</div>'; return; }
    const servers = getSavedServers();
    if (servers.length === 0) { listEl.innerHTML = '<div style="font-size:7px;color:#888;">No bookmarked servers yet.</div>'; return; }
    servers.forEach((s) => {
        const row = document.createElement('div');
        row.className = 'saved-list-row';
        const label = document.createElement('div');
        label.className = 'name';
        label.innerText = s.name + ' (' + s.code + ')';
        row.appendChild(label);
        const joinBtn = document.createElement('button');
        joinBtn.className = 'pixel-btn small';
        joinBtn.innerText = 'Join';
        joinBtn.onclick = () => { document.getElementById('join-room-code').value = s.code; joinMultiplayerRoom(); };
        row.appendChild(joinBtn);
        const delBtn = document.createElement('button');
        delBtn.className = 'pixel-btn danger small';
        delBtn.innerText = 'X';
        delBtn.onclick = async () => {
            try { await serversColRef().doc(s.id).delete(); serversCache = serversCache.filter(x => x.id !== s.id); renderServerList(); }
            catch (e) { notify('Could not delete: ' + e.message); }
        };
        row.appendChild(delBtn);
        listEl.appendChild(row);
    });
}
async function saveCurrentServerCode() {
    if (!requireOnline()) return;
    const code = document.getElementById('join-room-code').value.trim().toUpperCase();
    const name = document.getElementById('server-save-name').value.trim() || ('Server ' + (getSavedServers().length + 1));
    if (!code) return notify('Enter a code first!');
    try {
        const ref = await serversColRef().add({ name, code });
        serversCache.push({ id: ref.id, name, code });
        document.getElementById('server-save-name').value = '';
        renderServerList();
        notify('Server bookmarked!');
    } catch (e) { notify('Could not save: ' + e.message); }
}

// CANVAS PROCEDURAL TEXTURE & ICON GENERATION ENGINE
const textureCache = {};
const iconCache = {};

function generateBlockTexture(type) {
    if (textureCache[type]) return textureCache[type];
    const cvs = document.createElement('canvas');
    cvs.width = TILE_SIZE; cvs.height = TILE_SIZE;
    const c = cvs.getContext('2d');
    if (type === BLOCKS.AIR) { textureCache[type] = cvs; return cvs; }

    if (type === BLOCKS.TORCH) {
        c.fillStyle = '#5D4037'; c.fillRect(14, 16, 4, 16);
        c.fillStyle = '#e67e22'; c.beginPath(); c.moveTo(16, 2); c.lineTo(9, 16); c.lineTo(23, 16); c.closePath(); c.fill();
        c.fillStyle = '#f1c40f'; c.beginPath(); c.arc(16, 9, 4, 0, Math.PI * 2); c.fill();
        textureCache[type] = cvs; return cvs;
    }
    if (type === BLOCKS.WATER) {
        const grad = c.createLinearGradient(0, 0, 0, TILE_SIZE);
        grad.addColorStop(0, 'rgba(93,173,226,0.75)'); grad.addColorStop(1, 'rgba(41,128,185,0.88)');
        c.fillStyle = grad; c.fillRect(0, 0, TILE_SIZE, TILE_SIZE);
        c.strokeStyle = 'rgba(255,255,255,0.4)'; c.lineWidth = 1;
        c.beginPath(); c.moveTo(0, 5); c.lineTo(32, 5); c.stroke();
        textureCache[type] = cvs; return cvs;
    }

    c.fillStyle = BLOCK_PROPS[type].color;
    c.fillRect(0, 0, TILE_SIZE, TILE_SIZE);

    const pSize = 4;
    for (let x = 0; x < TILE_SIZE; x += pSize) {
        for (let y = 0; y < TILE_SIZE; y += pSize) {
            const rand = Math.random();
            if (rand < 0.2) { c.fillStyle = 'rgba(0,0,0,0.15)'; c.fillRect(x, y, pSize, pSize); }
            else if (rand > 0.8) { c.fillStyle = 'rgba(255,255,255,0.15)'; c.fillRect(x, y, pSize, pSize); }
        }
    }

    if (type === BLOCKS.GRASS) {
        c.fillStyle = '#4CAF50'; c.fillRect(0, 0, TILE_SIZE, 8);
        c.fillStyle = '#388E3C';
        for (let x = 0; x < TILE_SIZE; x += 4) { if (Math.random() > 0.4) c.fillRect(x, 8, 4, 4); }
    } else if (type === BLOCKS.WOOD) {
        c.fillStyle = '#5D4037'; c.fillRect(0, 0, 4, TILE_SIZE); c.fillRect(TILE_SIZE - 4, 0, 4, TILE_SIZE);
        c.fillStyle = '#4E342E'; c.fillRect(8, 0, 4, TILE_SIZE); c.fillRect(20, 0, 4, TILE_SIZE);
    } else if (type === BLOCKS.PLANK) {
        c.fillStyle = '#A1887F';
        c.fillRect(0, 10, TILE_SIZE, 2); c.fillRect(0, 22, TILE_SIZE, 2);
        c.fillRect(14, 0, 2, 10); c.fillRect(22, 12, 2, 10); c.fillRect(8, 24, 2, 8);
    } else if (type === BLOCKS.LEAVES) {
        c.fillStyle = 'rgba(20, 90, 30, 0.4)';
        for (let i = 0; i < 8; i++) c.fillRect(Math.random() * 24, Math.random() * 24, 8, 8);
    } else if (type === BLOCKS.SAND) {
        c.fillStyle = 'rgba(255,255,255,0.2)';
        for (let i = 0; i < 10; i++) c.fillRect(Math.random() * 28, Math.random() * 28, 2, 2);
    } else if (type === BLOCKS.COAL || type === BLOCKS.IRON || type === BLOCKS.GOLD) {
        let oreColor = '#111';
        if (type === BLOCKS.IRON) oreColor = '#e0e0e0';
        if (type === BLOCKS.GOLD) oreColor = '#fff000';
        c.fillStyle = oreColor;
        c.fillRect(6, 6, 8, 8); c.fillRect(18, 14, 6, 8); c.fillRect(10, 20, 8, 6);
    } else if (type === BLOCKS.CRAFTING_BENCH) {
        c.fillStyle = '#6d4c31'; c.fillRect(0, 12, TILE_SIZE, 3);
        c.fillStyle = '#3a3a3a'; c.beginPath(); c.arc(22, 22, 5, 0, Math.PI * 2); c.fill();
        c.strokeStyle = '#1a1a1a'; c.lineWidth = 1; c.stroke();
        c.fillStyle = '#8a5a3c'; c.fillRect(4, 4, 10, 6);
    } else if (type === BLOCKS.FURNACE) {
        c.fillStyle = '#3a3a3a'; c.fillRect(6, 18, 20, 12);
        c.fillStyle = '#e67e22'; c.fillRect(9, 20, 14, 7);
        c.fillStyle = '#ffcf6b'; c.fillRect(12, 22, 8, 3);
    }

    c.strokeStyle = 'rgba(0,0,0,0.15)';
    c.strokeRect(0, 0, TILE_SIZE, TILE_SIZE);

    textureCache[type] = cvs;
    return cvs;
}

function generateItemIcon(type) {
    if (iconCache[type]) return iconCache[type];
    const cvs = document.createElement('canvas');
    cvs.width = 32; cvs.height = 32;
    const c = cvs.getContext('2d');
    const prop = BLOCK_PROPS[type];
    if (!prop || type === BLOCKS.AIR) return cvs;

    if (prop.itemType === ITEM_TYPES.BLOCK) {
        const tex = generateBlockTexture(type);
        c.drawImage(tex, 4, 4, 24, 24);
        c.strokeStyle = '#000'; c.lineWidth = 2; c.strokeRect(4, 4, 24, 24);
    } else if (prop.itemType === ITEM_TYPES.MATERIAL) {
        c.fillStyle = prop.color;
        c.beginPath();
        c.moveTo(5, 20); c.lineTo(11, 10); c.lineTo(21, 10); c.lineTo(27, 20); c.lineTo(23, 26); c.lineTo(9, 26);
        c.closePath(); c.fill();
        c.strokeStyle = '#000'; c.lineWidth = 1.5; c.stroke();
        c.fillStyle = 'rgba(255,255,255,0.35)'; c.fillRect(9, 14, 14, 3);
    } else if (prop.itemType === ITEM_TYPES.TOOL) {
        let headColor = '#8D6E63';
        if (type === BLOCKS.STONE_PICKAXE) headColor = '#90A4AE';
        if (type === BLOCKS.IRON_PICKAXE) headColor = '#cfd8dc';
        if (type === BLOCKS.GOLD_PICKAXE) headColor = '#ffd700';
        c.fillStyle = '#5D4037';
        for (let i = 0; i < 14; i++) c.fillRect(8 + i, 22 - i, 3, 3);
        c.fillStyle = headColor;
        c.fillRect(16, 6, 10, 4); c.fillRect(22, 10, 4, 8); c.fillRect(10, 8, 8, 4); c.fillRect(8, 12, 4, 6);
        c.fillStyle = '#fff'; c.fillRect(20, 6, 2, 2);
    } else if (prop.itemType === ITEM_TYPES.ARMOR) {
        c.fillStyle = prop.color;
        if (prop.slot === 'head') { c.beginPath(); c.arc(16, 14, 10, Math.PI, 0); c.fill(); c.fillRect(6, 14, 20, 6); }
        else if (prop.slot === 'chest') { c.fillRect(8, 6, 16, 20); c.fillRect(4, 10, 6, 12); c.fillRect(22, 10, 6, 12); }
        else if (prop.slot === 'legs') { c.fillRect(8, 6, 7, 22); c.fillRect(17, 6, 7, 22); }
        else if (prop.slot === 'feet') { c.fillRect(6, 20, 9, 8); c.fillRect(17, 20, 9, 8); }
        c.strokeStyle = '#000'; c.lineWidth = 1; c.strokeRect(2, 2, 28, 28);
    }

    iconCache[type] = cvs;
    return cvs;
}

// GAME STATE & VARS
let world = new Uint8Array(WORLD_WIDTH * WORLD_HEIGHT);
let wallWorld = new Uint8Array(WORLD_WIDTH * WORLD_HEIGHT);
let blockDamageMap = new Float32Array(WORLD_WIDTH * WORLD_HEIGHT);

let player = {
    x: 0, y: 0, vx: 0, vy: 0, width: 20, height: 38,
    grounded: false, facing: 'right', animTime: 0, isWalking: false,
    health: 100, maxHealth: 100, fallSpeed: 0
};

let gameMode = 'survival';
let isMultiplayer = false;
let isHost = false;
let otherPlayers = new Map();

function emptySlot() { return { type: BLOCKS.AIR, count: 0, durability: 0 }; }

function resetHotbar() {
    hotbar = [emptySlot(), emptySlot(), emptySlot(), emptySlot(), emptySlot(), emptySlot(), emptySlot(), emptySlot()];
    inventoryStorage = new Array(24).fill(null).map(emptySlot);
    equipment = { head: emptySlot(), chest: emptySlot(), legs: emptySlot(), feet: emptySlot() };
    selectedSlot = 0;
}

let hotbar = [emptySlot(), emptySlot(), emptySlot(), emptySlot(), emptySlot(), emptySlot(), emptySlot(), emptySlot()];
let inventoryStorage = new Array(24).fill(null).map(emptySlot);
let equipment = { head: emptySlot(), chest: emptySlot(), legs: emptySlot(), feet: emptySlot() };

let craftingGrid = new Array(9).fill(null).map(emptySlot);
let craftResult = { type: BLOCKS.AIR, count: 0, durability: 0 };
let heldItem = null;

let camera = { x: 0, y: 0 };
let selectedSlot = 0;
let particles = [];
let worldSeed = Math.random() * 10000;
let lastFrameTime = performance.now();
let dayCycle = 0;
let frameCounter = 0;
let shakeTime = 0, shakeMag = 0;
const keys = {};

const STAR_FIELD = Array.from({ length: 90 }, () => ({ x: Math.random(), y: Math.random() * 0.65 }));

function clamp(v, min, max) { return Math.max(min, Math.min(max, v)); }
function triggerShake(time, mag) {
    if (!settings.shake) return;
    shakeTime = Math.max(shakeTime, time);
    shakeMag = Math.max(shakeMag, mag);
}
function getTotalDefense() {
    let d = 0;
    for (const key in equipment) { const it = equipment[key]; if (it.type !== BLOCKS.AIR && BLOCK_PROPS[it.type]) d += (BLOCK_PROPS[it.type].defense || 0); }
    return Math.min(80, d);
}

function notify(text) {
    const el = document.getElementById('notification');
    el.innerText = text;
    el.style.display = 'block';
    clearTimeout(notify._t);
    notify._t = setTimeout(() => { el.style.display = 'none'; }, 2800);
}

function showMenuSection(id) {
    document.querySelectorAll('.menu-card').forEach(el => { el.style.display = 'none'; });
    const target = document.getElementById(id);
    if (target) target.style.display = 'flex';
    if (id === 'mp-menu') { renderServerList(); renderHostWorldOptions(); startServerBrowserListener(); }
    else stopServerBrowserListener();
    if (id === 'sp-menu') { renderAccountWorldsList(); }
}

function pseudoNoise(x, y, seed) {
    let n = Math.sin(x * 12.9898 + y * 78.233 + seed) * 43758.5453;
    return n - Math.floor(n);
}

function isNearStation(blockType, radius) {
    radius = radius || 6;
    const pc = Math.floor((player.x + player.width / 2) / TILE_SIZE);
    const pr = Math.floor((player.y + player.height / 2) / TILE_SIZE);
    for (let r = pr - radius; r <= pr + radius; r++) {
        for (let c = pc - radius; c <= pc + radius; c++) {
            if (r < 0 || r >= WORLD_HEIGHT || c < 0 || c >= WORLD_WIDTH) continue;
            if (world[r * WORLD_WIDTH + c] === blockType) return true;
        }
    }
    return false;
}

function carveOreBlob(cx, cy, type, size) {
    for (let i = 0; i < size * 3; i++) {
        const dx = Math.floor((Math.random() - 0.5) * size * 1.6);
        const dy = Math.floor((Math.random() - 0.5) * size * 1.6);
        const x = cx + dx, y = cy + dy;
        if (x < 0 || x >= WORLD_WIDTH || y < 0 || y >= WORLD_HEIGHT) continue;
        const idx = y * WORLD_WIDTH + x;
        if (world[idx] === BLOCKS.STONE || world[idx] === BLOCKS.DEEP_STONE) world[idx] = type;
    }
}

function scatterOreVeins(seed) {
    const veinTypes = [
        { type: BLOCKS.COAL, minDepth: 3, size: [3, 6], chance: 0.010 },
        { type: BLOCKS.IRON, minDepth: 15, size: [2, 5], chance: 0.007 },
        { type: BLOCKS.GOLD, minDepth: 35, size: [2, 4], chance: 0.003 }
    ];
    for (let x = 2; x < WORLD_WIDTH - 2; x++) {
        const surfaceHeight = Math.floor(Math.sin(x * 0.04 + seed) * 8) + 35;
        for (let y = 2; y < WORLD_HEIGHT - 2; y++) {
            const index = y * WORLD_WIDTH + x;
            if (world[index] !== BLOCKS.STONE && world[index] !== BLOCKS.DEEP_STONE) continue;
            const depth = y - surfaceHeight;
            for (const vein of veinTypes) {
                if (depth < vein.minDepth) continue;
                const roll = pseudoNoise(x * 0.37, y * 0.53, seed + vein.type * 77);
                if (roll < vein.chance) {
                    const size = vein.size[0] + Math.floor(Math.random() * (vein.size[1] - vein.size[0] + 1));
                    carveOreBlob(x, y, vein.type, size);
                }
            }
        }
    }
}

function generateContinuousCaves(seedVal) {
    const seed = seedVal || Math.random() * 10000;
    worldSeed = seed;
    world.fill(BLOCKS.AIR);
    wallWorld.fill(BLOCKS.AIR);
    blockDamageMap.fill(0);

    for (let x = 0; x < WORLD_WIDTH; x++) {
        const biomeNoise = pseudoNoise(x * 0.015, 0, seed + 500);
        const isDesert = biomeNoise < 0.22;
        const lakeNoise = pseudoNoise(x * 0.03, 0, seed + 900);
        const isLake = !isDesert && lakeNoise > 0.88;

        let surfaceHeight = Math.floor(Math.sin(x * 0.04 + seed) * 8) + 35;
        if (isLake) surfaceHeight += 2;

        for (let y = 0; y < WORLD_HEIGHT; y++) {
            const index = y * WORLD_WIDTH + x;
            if (y > surfaceHeight) {
                wallWorld[index] = BLOCKS.DIRT;
                let c1 = Math.sin(x * 0.08 + y * 0.05 + seed);
                let c2 = Math.cos(x * 0.05 - y * 0.08 + seed * 2);
                let wormVal = Math.abs(c1 * c2);
                const depth = y - surfaceHeight;
                const caveThreshold = Math.max(0.13, 0.27 - Math.min(1, depth / 90) * 0.14);

                if (wormVal > caveThreshold || y <= surfaceHeight + 2) {
                    if (y > surfaceHeight + 8) {
                        world[index] = (y > surfaceHeight + 55) ? BLOCKS.DEEP_STONE : BLOCKS.STONE;
                    } else {
                        world[index] = (isDesert && y <= surfaceHeight + 3) ? BLOCKS.SAND : BLOCKS.DIRT;
                    }
                }
            } else if (y === surfaceHeight) {
                if (isLake) { world[index] = BLOCKS.WATER; wallWorld[index] = BLOCKS.SAND; }
                else if (isDesert) { world[index] = BLOCKS.SAND; wallWorld[index] = BLOCKS.SAND; }
                else {
                    world[index] = BLOCKS.GRASS; wallWorld[index] = BLOCKS.DIRT;
                    if (x > 5 && x < WORLD_WIDTH - 5 && pseudoNoise(x, 0, seed) < 0.12) generateTree(x, y - 1);
                }
            }
        }
    }

    scatterOreVeins(seed);
    spawnPlayer();
    player.health = player.maxHealth;
    player.fallSpeed = 0;
}

function generateTree(startX, startY) {
    const trunkHeight = 3 + Math.floor(Math.random() * 3);
    for (let i = 0; i < trunkHeight; i++) { const ty = startY - i; if (ty >= 0) world[ty * WORLD_WIDTH + startX] = BLOCKS.WOOD; }
    const leafTop = startY - trunkHeight;
    for (let lx = -1; lx <= 1; lx++) {
        for (let ly = -1; ly <= 1; ly++) {
            const index = (leafTop + ly) * WORLD_WIDTH + (startX + lx);
            if (index >= 0 && index < world.length && world[index] === BLOCKS.AIR) world[index] = BLOCKS.LEAVES;
        }
    }
}

function spawnPlayer() {
    const spawnX = Math.floor(WORLD_WIDTH / 2);
    for (let y = 0; y < WORLD_HEIGHT; y++) {
        if (BLOCK_PROPS[world[y * WORLD_WIDTH + spawnX]].solid) {
            player.x = spawnX * TILE_SIZE; player.y = (y - 3) * TILE_SIZE; player.vx = 0; player.vy = 0;
            break;
        }
    }
}

function resizeCanvas() { canvas.width = window.innerWidth; canvas.height = window.innerHeight; }
window.addEventListener('resize', resizeCanvas);
resizeCanvas();

window.addEventListener('keydown', e => {
    const tag = document.activeElement ? document.activeElement.tagName : '';
    const typing = tag === 'INPUT' || tag === 'TEXTAREA';
    if (typing) return;

    keys[e.code] = true;
    if (e.code.startsWith('Digit')) {
        const num = parseInt(e.code.replace('Digit', '')) - 1;
        if (num >= 0 && num < hotbar.length) { selectedSlot = num; updateUI(); }
    }
    if (e.code === 'KeyC') toggleCraftingModal();
    if (e.code === 'KeyE') toggleInventoryModal();
    if (e.code === 'Escape') { toggleCraftingModal(true); toggleInventoryModal(true); closeWorldsModal(); }
});
window.addEventListener('keyup', e => keys[e.code] = false);

window.addEventListener('mousemove', e => {
    const dragEl = document.getElementById('dragged-item');
    if (heldItem && heldItem.count > 0 && heldItem.type !== BLOCKS.AIR) {
        dragEl.style.display = 'block';
        dragEl.style.left = e.clientX + 'px';
        dragEl.style.top = e.clientY + 'px';
        const dragCtx = dragEl.getContext('2d');
        dragCtx.clearRect(0, 0, 32, 32);
        dragCtx.drawImage(generateItemIcon(heldItem.type), 0, 0);
    } else {
        dragEl.style.display = 'none';
    }
});

canvas.addEventListener('mousedown', e => {
    if (document.getElementById('ui-layer').style.display === 'none') return;

    const rect = canvas.getBoundingClientRect();
    const mouseX = e.clientX - rect.left + camera.x;
    const mouseY = e.clientY - rect.top + camera.y;

    const playerCenterX = player.x + player.width / 2;
    const playerCenterY = player.y + player.height / 2;
    const dist = Math.hypot(mouseX - playerCenterX, mouseY - playerCenterY);

    if (gameMode !== 'creative' && dist > INTERACTION_RANGE) { notify("Out of mining range!"); return; }

    const col = Math.floor(mouseX / TILE_SIZE);
    const row = Math.floor(mouseY / TILE_SIZE);

    if (col >= 0 && col < WORLD_WIDTH && row >= 0 && row < WORLD_HEIGHT) {
        const index = row * WORLD_WIDTH + col;

        if (e.button === 0) {
            const block = world[index];
            if (block !== BLOCKS.AIR && !BLOCK_PROPS[block].unbreakable) {
                let activeItem = hotbar[selectedSlot];
                let miningPower = HAND_POWER;
                let usingTool = false;

                if (activeItem && BLOCK_PROPS[activeItem.type] && BLOCK_PROPS[activeItem.type].itemType === ITEM_TYPES.TOOL) {
                    miningPower = BLOCK_PROPS[activeItem.type].power;
                    usingTool = true;
                }

                const requiredPower = BLOCK_PROPS[block].minPower || 0;
                if (gameMode !== 'creative' && miningPower < requiredPower) {
                    notify(BLOCK_PROPS[block].name + " is too tough - need a better pickaxe!");
                    return;
                }

                if (usingTool && gameMode !== 'creative') {
                    activeItem.durability--;
                    if (activeItem.durability <= 0) {
                        hotbar[selectedSlot] = emptySlot();
                        notify("Tool Broke!");
                    }
                }

                blockDamageMap[index] += miningPower;
                addParticle(col * TILE_SIZE + 16, row * TILE_SIZE + 16, BLOCK_PROPS[block].color);

                if (blockDamageMap[index] >= BLOCK_PROPS[block].hardness || gameMode === 'creative') {
                    if (gameMode !== 'creative') addItemToInventory(block, 1);
                    world[index] = BLOCKS.AIR;
                    blockDamageMap[index] = 0;
                    broadcastBlockChange(col, row, BLOCKS.AIR);
                    triggerShake(4, 3);
                }
                updateUI();
            }
        } else if (e.button === 2) {
            const active = hotbar[selectedSlot];
            if (world[index] === BLOCKS.AIR && active && BLOCK_PROPS[active.type] && BLOCK_PROPS[active.type].itemType === ITEM_TYPES.BLOCK && active.count > 0) {
                world[index] = active.type;
                if (gameMode !== 'creative') {
                    active.count--;
                    if (active.count <= 0) hotbar[selectedSlot] = emptySlot();
                }
                broadcastBlockChange(col, row, active.type);
                updateUI();
            }
        }
    }
});
canvas.addEventListener('contextmenu', e => e.preventDefault());

function addItemToInventory(type, count) {
    const prop = BLOCK_PROPS[type];
    const stackable = prop.itemType !== ITEM_TYPES.TOOL && prop.itemType !== ITEM_TYPES.ARMOR;
    const allSlots = hotbar.concat(inventoryStorage);

    for (let slot of allSlots) {
        if (slot.type === type && stackable) { slot.count += count; updateUI(); return; }
    }
    for (let slot of allSlots) {
        if (slot.type === BLOCKS.AIR || slot.count === 0) {
            slot.type = type; slot.count = count; slot.durability = prop.maxDurability || 0;
            updateUI(); return;
        }
    }
    notify("Inventory Full!");
}

function addParticle(x, y, color) {
    for (let i = 0; i < 6; i++) {
        particles.push({ x, y, vx: (Math.random() - 0.5) * 5, vy: (Math.random() - 0.5) * 5, life: 16, maxLife: 16, color });
    }
}

function maybeSpawnAmbientDust() {
    if (!settings.dust) return;
    if (Math.random() > 0.06) return;
    const tc = Math.floor(player.x / TILE_SIZE);
    const tr = Math.floor(player.y / TILE_SIZE);
    if (tr < 0 || tr >= WORLD_HEIGHT || tc < 0 || tc >= WORLD_WIDTH) return;
    const underground = wallWorld[tr * WORLD_WIDTH + tc] !== BLOCKS.AIR;
    if (!underground) return;
    particles.push({
        x: player.x + (Math.random() - 0.5) * 500, y: player.y + (Math.random() - 0.5) * 400,
        vx: (Math.random() - 0.5) * 0.25, vy: -Math.random() * 0.25,
        life: 140, maxLife: 140, color: 'rgba(190,190,210,0.22)'
    });
}

function updatePhysics(dt) {
    const timeFactor = Math.min(dt / 16.6, 2.0);
    const accel = 0.85 * timeFactor;
    const maxSpeed = 3.8;
    const friction = 0.75;

    player.isWalking = false;

    if (keys['KeyA'] || keys['ArrowLeft']) { player.vx = Math.max(player.vx - accel, -maxSpeed); player.facing = 'left'; player.isWalking = true; }
    else if (keys['KeyD'] || keys['ArrowRight']) { player.vx = Math.min(player.vx + accel, maxSpeed); player.facing = 'right'; player.isWalking = true; }
    else { player.vx *= Math.pow(friction, timeFactor); }

    if ((keys['KeyW'] || keys['Space'] || keys['ArrowUp']) && player.grounded) { player.vy = -10.0; player.grounded = false; }

    const wasGrounded = player.grounded;
    player.grounded = false;

    player.vy += 0.52 * timeFactor;
    if (player.vy > 12) player.vy = 12;
    player.fallSpeed = Math.max(player.fallSpeed || 0, player.vy);

    player.x += player.vx * timeFactor;
    checkCollision(true);
    player.y += player.vy * timeFactor;
    checkCollision(false);

    if (player.grounded && !wasGrounded) {
        if (gameMode !== 'creative' && player.fallSpeed > 9) {
            let dmg = Math.floor((player.fallSpeed - 9) * 6);
            const defense = getTotalDefense();
            dmg = Math.max(0, Math.floor(dmg * (1 - defense / 100)));
            if (dmg > 0) {
                player.health = Math.max(0, player.health - dmg);
                if (dmg > 3) notify('Ouch! -' + dmg + ' HP');
                updateHealthUI();
                if (player.health <= 0) {
                    notify('You died! Respawning...');
                    spawnPlayer();
                    player.health = player.maxHealth;
                    updateHealthUI();
                }
            }
        }
        player.fallSpeed = 0;
    }

    if (player.isWalking) player.animTime += 0.15 * timeFactor;
    else player.animTime = 0;

    const targetCamX = clamp(player.x + player.width / 2 - canvas.width / 2, 0, Math.max(0, WORLD_WIDTH * TILE_SIZE - canvas.width));
    const targetCamY = clamp(player.y + player.height / 2 - canvas.height / 2, 0, Math.max(0, WORLD_HEIGHT * TILE_SIZE - canvas.height));
    const camLerp = settings.smoothCamera ? Math.min(1, 0.15 * timeFactor) : 1;
    camera.x += (targetCamX - camera.x) * camLerp;
    camera.y += (targetCamY - camera.y) * camLerp;

    maybeSpawnAmbientDust();
    if (isMultiplayer) broadcastPlayerPos();
}

function checkCollision(horizontal) {
    const left = Math.floor(player.x / TILE_SIZE);
    const right = Math.floor((player.x + player.width) / TILE_SIZE);
    const top = Math.floor(player.y / TILE_SIZE);
    const bottom = Math.floor((player.y + player.height) / TILE_SIZE);

    for (let r = top; r <= bottom; r++) {
        for (let c = left; c <= right; c++) {
            if (r >= 0 && r < WORLD_HEIGHT && c >= 0 && c < WORLD_WIDTH) {
                const blockType = world[r * WORLD_WIDTH + c];
                if (BLOCK_PROPS[blockType].solid) {
                    if (horizontal) {
                        if (player.vx > 0) player.x = c * TILE_SIZE - player.width - 0.01;
                        if (player.vx < 0) player.x = (c + 1) * TILE_SIZE + 0.01;
                        player.vx = 0;
                    } else {
                        if (player.vy > 0) { player.y = r * TILE_SIZE - player.height - 0.01; player.grounded = true; }
                        if (player.vy < 0) player.y = (r + 1) * TILE_SIZE + 0.01;
                        player.vy = 0;
                    }
                }
            }
        }
    }
}

function drawCelestial(dayLight) {
    if (dayLight < 0.4) {
        const starAlpha = (0.4 - dayLight) / 0.4;
        ctx.fillStyle = `rgba(255,255,255,${starAlpha})`;
        for (let s = 0; s < STAR_FIELD.length; s++) { const st = STAR_FIELD[s]; ctx.fillRect(st.x * canvas.width, st.y * canvas.height, 2, 2); }
    }
    const angle = dayCycle % (Math.PI * 2);
    const cx = canvas.width * 0.5 + Math.cos(angle) * canvas.width * 0.42;
    const cy = canvas.height * 0.35 - Math.sin(angle) * canvas.height * 0.3;
    ctx.fillStyle = dayLight > 0.5 ? '#fff6a0' : '#dfe6f0';
    ctx.beginPath(); ctx.arc(cx, cy, dayLight > 0.5 ? 22 : 16, 0, Math.PI * 2); ctx.fill();
    ctx.strokeStyle = 'rgba(255,255,255,0.3)'; ctx.lineWidth = 4; ctx.stroke();
}

function drawParallaxMountains(dayLight) {
    const horizonY = canvas.height * 0.6;
    function layer(offsetFactor, amplitude, freq, alpha, baseY, shadeBoost) {
        ctx.beginPath();
        ctx.moveTo(0, canvas.height);
        for (let x = 0; x <= canvas.width; x += 24) {
            const worldX = camera.x * offsetFactor + x;
            const h = Math.sin(worldX * freq) * amplitude + Math.sin(worldX * freq * 2.3 + 50) * amplitude * 0.4;
            ctx.lineTo(x, baseY - h);
        }
        ctx.lineTo(canvas.width, canvas.height);
        ctx.closePath();
        const shade = Math.floor(18 + dayLight * 45) + shadeBoost;
        ctx.fillStyle = `rgba(${shade},${shade + 8},${shade + 26},${alpha})`;
        ctx.fill();
    }
    layer(0.15, 55, 0.004, 0.5, horizonY - 30, 0);
    layer(0.32, 85, 0.006, 0.75, horizonY + 5, -8);
}

let lightCanvas = null, lightCtx = null;
function ensureLightCanvas() {
    if (!lightCanvas || lightCanvas.width !== canvas.width || lightCanvas.height !== canvas.height) {
        lightCanvas = document.createElement('canvas');
        lightCanvas.width = canvas.width; lightCanvas.height = canvas.height;
        lightCtx = lightCanvas.getContext('2d');
    }
}

function renderLighting(dayLight, startCol, endCol, startRow, endRow) {
    if (!settings.lighting) return;
    const darkness = (1 - dayLight) * 0.82;
    if (darkness <= 0.02) return;

    ensureLightCanvas();
    lightCtx.globalCompositeOperation = 'source-over';
    lightCtx.clearRect(0, 0, lightCanvas.width, lightCanvas.height);
    lightCtx.fillStyle = `rgba(6,8,20,${darkness})`;
    lightCtx.fillRect(0, 0, lightCanvas.width, lightCanvas.height);

    lightCtx.globalCompositeOperation = 'destination-out';
    function cut(wx, wy, radius, intensity) {
        const sx = wx - camera.x, sy = wy - camera.y;
        const grad = lightCtx.createRadialGradient(sx, sy, 0, sx, sy, radius);
        grad.addColorStop(0, `rgba(255,255,255,${intensity})`);
        grad.addColorStop(0.55, `rgba(255,255,255,${intensity * 0.55})`);
        grad.addColorStop(1, 'rgba(255,255,255,0)');
        lightCtx.fillStyle = grad;
        lightCtx.beginPath(); lightCtx.arc(sx, sy, radius, 0, Math.PI * 2); lightCtx.fill();
    }

    cut(player.x + player.width / 2, player.y + player.height / 2, TILE_SIZE * 6, 1.0);
    otherPlayers.forEach(p => cut(p.x + player.width / 2, p.y + player.height / 2, TILE_SIZE * 5.5, 0.95));

    for (let r = startRow; r < endRow; r++) {
        for (let c = startCol; c < endCol; c++) {
            const b = world[r * WORLD_WIDTH + c];
            const props = BLOCK_PROPS[b];
            if (props && props.light) cut(c * TILE_SIZE + 16, r * TILE_SIZE + 16, TILE_SIZE * (props.lightRadius || 4), 1.0);
        }
    }

    lightCtx.globalCompositeOperation = 'source-over';
    ctx.drawImage(lightCanvas, 0, 0);
}

function drawWarmGlow(startCol, endCol, startRow, endRow, dayLight) {
    if (!settings.lighting) return;
    if (dayLight > 0.6) return;
    ctx.save();
    ctx.globalCompositeOperation = 'lighter';
    function glow(wx, wy, radius, r, g, b) {
        const sx = wx - camera.x, sy = wy - camera.y;
        const grad = ctx.createRadialGradient(sx, sy, 0, sx, sy, radius);
        grad.addColorStop(0, `rgba(${r},${g},${b},0.35)`);
        grad.addColorStop(1, `rgba(${r},${g},${b},0)`);
        ctx.fillStyle = grad;
        ctx.beginPath(); ctx.arc(sx, sy, radius, 0, Math.PI * 2); ctx.fill();
    }
    for (let r = startRow; r < endRow; r++) {
        for (let c = startCol; c < endCol; c++) {
            const b = world[r * WORLD_WIDTH + c];
            if (b === BLOCKS.TORCH) glow(c * TILE_SIZE + 16, r * TILE_SIZE + 16, TILE_SIZE * 2.4, 255, 170, 80);
            else if (b === BLOCKS.FURNACE) glow(c * TILE_SIZE + 16, r * TILE_SIZE + 16, TILE_SIZE * 2.1, 255, 110, 60);
        }
    }
    ctx.restore();
}

function drawVignette() {
    if (!settings.vignette) return;
    const grad = ctx.createRadialGradient(canvas.width / 2, canvas.height / 2, canvas.height * 0.35, canvas.width / 2, canvas.height / 2, canvas.height * 0.78);
    grad.addColorStop(0, 'rgba(0,0,0,0)');
    grad.addColorStop(1, 'rgba(0,0,0,0.4)');
    ctx.fillStyle = grad;
    ctx.fillRect(0, 0, canvas.width, canvas.height);
}

function render(dt) {
    dayCycle += dt * 0.000012;
    const dayLight = (Math.sin(dayCycle) + 1) / 2;

    const shaking = shakeTime > 0;
    if (shaking) {
        ctx.save();
        const ox = (Math.random() - 0.5) * shakeMag;
        const oy = (Math.random() - 0.5) * shakeMag;
        ctx.translate(ox, oy);
        shakeTime--; shakeMag *= 0.9;
    }

    const skyTopR = Math.floor(15 + dayLight * 115);
    const skyTopG = Math.floor(15 + dayLight * 175);
    const skyTopB = Math.floor(30 + dayLight * 225);
    const skyGrad = ctx.createLinearGradient(0, 0, 0, canvas.height);
    skyGrad.addColorStop(0, `rgb(${skyTopR}, ${skyTopG}, ${skyTopB})`);
    skyGrad.addColorStop(1, `rgb(${Math.floor(skyTopR * 0.6)}, ${Math.floor(skyTopG * 0.6)}, ${Math.floor(skyTopB * 0.6)})`);
    ctx.fillStyle = skyGrad;
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    drawCelestial(dayLight);
    drawParallaxMountains(dayLight);

    const startCol = Math.max(0, Math.floor(camera.x / TILE_SIZE));
    const endCol = Math.min(WORLD_WIDTH, Math.ceil((camera.x + canvas.width) / TILE_SIZE));
    const startRow = Math.max(0, Math.floor(camera.y / TILE_SIZE));
    const endRow = Math.min(WORLD_HEIGHT, Math.ceil((camera.y + canvas.height) / TILE_SIZE));

    for (let r = startRow; r < endRow; r++) {
        for (let c = startCol; c < endCol; c++) {
            const index = r * WORLD_WIDTH + c;
            const block = world[index];
            const wall = wallWorld[index];
            const x = Math.floor(c * TILE_SIZE - camera.x);
            const y = Math.floor(r * TILE_SIZE - camera.y);

            if (block === BLOCKS.AIR && wall !== BLOCKS.AIR) {
                ctx.fillStyle = '#1c1310';
                ctx.fillRect(x, y, TILE_SIZE, TILE_SIZE);
            } else if (block !== BLOCKS.AIR) {
                const tex = generateBlockTexture(block);
                ctx.drawImage(tex, x, y);
                if (blockDamageMap[index] > 0 && !BLOCK_PROPS[block].unbreakable) {
                    const dmgRatio = blockDamageMap[index] / BLOCK_PROPS[block].hardness;
                    ctx.fillStyle = `rgba(0, 0, 0, ${dmgRatio * 0.75})`;
                    ctx.fillRect(x, y, TILE_SIZE, TILE_SIZE);
                }
            }
        }
    }

    particles.forEach((p, idx) => {
        p.x += p.vx; p.y += p.vy; p.life--;
        ctx.fillStyle = p.color;
        const size = Math.max(1, Math.floor((p.life / p.maxLife) * 5));
        ctx.fillRect(p.x - camera.x, p.y - camera.y, size, size);
        if (p.life <= 0) particles.splice(idx, 1);
    });

    otherPlayers.forEach(p => drawPlayer(p.x, p.y, p.facing, false, p.name, p.animTime || 0, p.skin, p.armor));
    drawPlayer(player.x, player.y, player.facing, true, document.getElementById('player-name').value, player.animTime, currentSkin, {
        head: equipment.head.type, chest: equipment.chest.type, legs: equipment.legs.type, feet: equipment.feet.type
    });

    renderLighting(dayLight, startCol, endCol, startRow, endRow);
    drawWarmGlow(startCol, endCol, startRow, endRow, dayLight);

    if (shaking) ctx.restore();

    drawVignette();
}

function drawPlayer(px, py, facing, isSelf, name, animTime, skin, armor) {
    skin = skin || currentSkin;
    const sx = Math.floor(px - camera.x);
    const sy = Math.floor(py - camera.y);
    const legOffset = Math.sin(animTime * 2) * 4;

    ctx.fillStyle = skin.shirt || '#e74c3c';
    ctx.fillRect(sx + 2, sy + 10, player.width - 4, 16);

    ctx.fillStyle = '#2c3e50';
    ctx.fillRect(sx + 3, sy + 26, 6, 12 + legOffset);
    ctx.fillRect(sx + 11, sy + 26, 6, 12 - legOffset);
    ctx.fillStyle = skin.pants || '#2c3e50';
    ctx.fillRect(sx + 3, sy + 26, 6, 8 + legOffset * 0.5);
    ctx.fillRect(sx + 11, sy + 26, 6, 8 - legOffset * 0.5);

    ctx.fillStyle = skin.skin || '#ffcc99';
    ctx.fillRect(sx + 1, sy, player.width - 2, 10);

    ctx.fillStyle = skin.hair || '#3b2412';
    ctx.fillRect(sx, sy - 2, player.width, 4);

    ctx.fillStyle = '#000';
    const eyeX = facing === 'right' ? sx + 13 : sx + 4;
    ctx.fillRect(eyeX, sy + 3, 3, 3);

    if (armor) {
        if (armor.chest && armor.chest !== BLOCKS.AIR && BLOCK_PROPS[armor.chest]) {
            ctx.fillStyle = BLOCK_PROPS[armor.chest].color;
            ctx.fillRect(sx + 2, sy + 10, player.width - 4, 10);
        }
        if (armor.legs && armor.legs !== BLOCKS.AIR && BLOCK_PROPS[armor.legs]) {
            ctx.fillStyle = BLOCK_PROPS[armor.legs].color;
            ctx.fillRect(sx + 3, sy + 26, 6, 8); ctx.fillRect(sx + 11, sy + 26, 6, 8);
        }
        if (armor.head && armor.head !== BLOCKS.AIR && BLOCK_PROPS[armor.head]) {
            ctx.fillStyle = BLOCK_PROPS[armor.head].color;
            ctx.fillRect(sx, sy - 2, player.width, 6);
        }
        if (armor.feet && armor.feet !== BLOCKS.AIR && BLOCK_PROPS[armor.feet]) {
            ctx.fillStyle = BLOCK_PROPS[armor.feet].color;
            ctx.fillRect(sx + 3, sy + 34, 6, 4); ctx.fillRect(sx + 11, sy + 34, 6, 4);
        }
    }

    if (isSelf) {
        ctx.strokeStyle = 'rgba(255,255,255,0.5)'; ctx.lineWidth = 1;
        ctx.strokeRect(sx + 1, sy - 2, player.width - 2, player.height + 2);
    }

    ctx.fillStyle = 'rgba(0,0,0,0.6)';
    ctx.fillRect(sx + player.width / 2 - 24, sy - 18, 48, 12);
    ctx.strokeStyle = '#3a3a52';
    ctx.strokeRect(sx + player.width / 2 - 24, sy - 18, 48, 12);

    ctx.fillStyle = '#fff';
    ctx.font = '6px "Press Start 2P"';
    ctx.textAlign = 'center';
    ctx.fillText(name.substring(0, 8), sx + player.width / 2, sy - 10);
}

const minimapCanvas = document.getElementById('minimap-canvas');
const minimapCtx = minimapCanvas.getContext('2d');
function renderMinimap() {
    minimapCtx.clearRect(0, 0, 150, 75);
    minimapCtx.fillStyle = '#0d0e15';
    minimapCtx.fillRect(0, 0, 150, 75);
    const stepX = WORLD_WIDTH / 150;
    for (let mx = 0; mx < 150; mx++) {
        const wx = Math.floor(mx * stepX);
        for (let y = 0; y < WORLD_HEIGHT; y++) {
            const b = world[y * WORLD_WIDTH + wx];
            if (b !== BLOCKS.AIR) {
                const my = Math.floor((y / WORLD_HEIGHT) * 75);
                minimapCtx.fillStyle = BLOCK_PROPS[b].color === 'transparent' ? '#000' : BLOCK_PROPS[b].color;
                minimapCtx.fillRect(mx, my, 1, 2);
                break;
            }
        }
    }
    const px = Math.floor((player.x / TILE_SIZE / WORLD_WIDTH) * 150);
    const py = Math.floor((player.y / TILE_SIZE / WORLD_HEIGHT) * 75);
    minimapCtx.fillStyle = '#e74c3c';
    minimapCtx.fillRect(px - 1, py - 1, 3, 3);
    otherPlayers.forEach(p => {
        const ox = Math.floor((p.x / TILE_SIZE / WORLD_WIDTH) * 150);
        const oy = Math.floor((p.y / TILE_SIZE / WORLD_HEIGHT) * 75);
        minimapCtx.fillStyle = '#3498db';
        minimapCtx.fillRect(ox - 1, oy - 1, 3, 3);
    });
}

function updateHealthUI() {
    const fill = document.getElementById('health-bar-fill');
    if (fill) fill.style.width = Math.max(0, Math.min(100, player.health)) + '%';
}
function updatePlayerCountDisplay() {
    const el = document.getElementById('player-count-display');
    if (el) el.innerText = 'PLAYERS: ' + (otherPlayers.size + 1);
}

// CRAFTING SYSTEM WITH DRAG & DROP
function toggleCraftingModal(forceClose) {
    const modal = document.getElementById('crafting-modal');
    const backdrop = document.getElementById('crafting-backdrop');
    const isVis = modal.style.display === 'flex';
    if (forceClose || isVis) {
        for (let i = 0; i < 9; i++) {
            if (craftingGrid[i].type !== BLOCKS.AIR && craftingGrid[i].count > 0) {
                addItemToInventory(craftingGrid[i].type, craftingGrid[i].count);
                craftingGrid[i] = emptySlot();
            }
        }
        checkCraftingRecipe();
        modal.style.display = 'none'; backdrop.style.display = 'none';
    } else {
        modal.style.display = 'flex'; backdrop.style.display = 'block';
        renderCraftingGrid(); renderRecipeSideBook();
    }
}

function renderCraftingGrid() {
    const gridEl = document.getElementById('crafting-grid');
    gridEl.innerHTML = '';
    craftingGrid.forEach((slot, idx) => {
        const el = document.createElement('div');
        el.className = 'slot';
        renderSlotInElement(el, slot);
        el.onclick = () => { handleSlotClick(craftingGrid, idx); renderCraftingGrid(); };
        gridEl.appendChild(el);
    });
    const resEl = document.getElementById('crafting-result');
    renderSlotInElement(resEl, craftResult);
}

function renderRecipeSideBook() {
    const sideEl = document.getElementById('recipe-book-list');
    sideEl.innerHTML = '';
    RECIPES.forEach(r => {
        const need = r.station === 'bench' ? BLOCKS.CRAFTING_BENCH : (r.station === 'furnace' ? BLOCKS.FURNACE : null);
        const unlocked = !need || isNearStation(need);
        const card = document.createElement('div');
        card.className = 'recipe-card' + (unlocked ? '' : ' locked');
        const iconCvs = generateItemIcon(r.result.type);
        const iconImg = document.createElement('img');
        iconImg.src = iconCvs.toDataURL();
        iconImg.style.width = '24px'; iconImg.style.height = '24px';
        card.appendChild(iconImg);
        const info = document.createElement('div');
        let reqLine = '';
        if (r.station === 'bench') reqLine = `<br><span style="color:${unlocked ? '#2ecc71' : '#e74c3c'};font-size:6px;">${unlocked ? '' : '[locked] '}Needs Crafting Bench</span>`;
        if (r.station === 'furnace') reqLine = `<br><span style="color:${unlocked ? '#2ecc71' : '#e74c3c'};font-size:6px;">${unlocked ? '' : '[locked] '}Needs Furnace</span>`;
        info.innerHTML = `<b style="color:var(--pixel-gold-bright);">${r.name}</b><br>Yield: ${r.result.count}x${reqLine}`;
        card.appendChild(info);
        card.onclick = () => fillCraftingGridFromRecipe(r);
        sideEl.appendChild(card);
    });
}

function fillCraftingGridFromRecipe(recipe) {
    if (recipe.station) {
        const need = recipe.station === 'bench' ? BLOCKS.CRAFTING_BENCH : BLOCKS.FURNACE;
        if (!isNearStation(need)) {
            notify(recipe.station === 'bench' ? "Needs a nearby Crafting Bench!" : "Needs a nearby Furnace!");
            return;
        }
    }
    for (let i = 0; i < 9; i++) {
        if (craftingGrid[i].type !== BLOCKS.AIR) { addItemToInventory(craftingGrid[i].type, craftingGrid[i].count); craftingGrid[i] = emptySlot(); }
    }
    let missing = false;
    const allSlots = hotbar.concat(inventoryStorage);
    recipe.grid.forEach((reqBlock, idx) => {
        if (reqBlock !== null) {
            let found = false;
            for (let slot of allSlots) {
                if (slot.type === reqBlock && slot.count > 0) {
                    slot.count--;
                    if (slot.count <= 0) { slot.type = BLOCKS.AIR; slot.durability = 0; }
                    craftingGrid[idx] = { type: reqBlock, count: 1, durability: BLOCK_PROPS[reqBlock].maxDurability || 0 };
                    found = true; break;
                }
            }
            if (!found) missing = true;
        }
    });
    if (missing) notify("Missing ingredients!");
    checkCraftingRecipe();
    renderCraftingGrid();
    updateUI();
}

function handleSlotClick(container, index) {
    let slot = container[index];
    if (!heldItem || heldItem.count === 0 || heldItem.type === BLOCKS.AIR) {
        if (slot.type !== BLOCKS.AIR && slot.count > 0) { heldItem = { ...slot }; container[index] = emptySlot(); }
    } else {
        const prop = BLOCK_PROPS[heldItem.type];
        if (slot.type === BLOCKS.AIR) { container[index] = { ...heldItem }; heldItem = null; }
        else if (slot.type === heldItem.type && prop.itemType !== ITEM_TYPES.TOOL && prop.itemType !== ITEM_TYPES.ARMOR) { slot.count += heldItem.count; heldItem = null; }
        else { let temp = { ...slot }; container[index] = { ...heldItem }; heldItem = temp; }
    }
    checkCraftingRecipe();
    updateUI();
}

function handleEquipClick(slotKey) {
    const slot = equipment[slotKey];
    if (!heldItem || heldItem.count === 0 || heldItem.type === BLOCKS.AIR) {
        if (slot.type !== BLOCKS.AIR) { heldItem = { ...slot }; equipment[slotKey] = emptySlot(); }
    } else {
        const prop = BLOCK_PROPS[heldItem.type];
        if (prop && prop.itemType === ITEM_TYPES.ARMOR && prop.slot === slotKey) {
            const temp = { ...slot };
            equipment[slotKey] = { ...heldItem, count: 1 };
            heldItem = (temp.type !== BLOCKS.AIR) ? temp : null;
        } else {
            notify("That doesn't fit there!");
        }
    }
    renderInventoryModal();
    updateUI();
}

function checkCraftingRecipe() {
    craftResult = emptySlot();
    for (let recipe of RECIPES) {
        let matches = true;
        for (let i = 0; i < 9; i++) {
            let targetType = recipe.grid[i];
            let currentType = craftingGrid[i].type;
            if (targetType === null && currentType !== BLOCKS.AIR) matches = false;
            if (targetType !== null && currentType !== targetType) matches = false;
        }
        if (matches && recipe.station) {
            const need = recipe.station === 'bench' ? BLOCKS.CRAFTING_BENCH : BLOCKS.FURNACE;
            if (!isNearStation(need)) matches = false;
        }
        if (matches) {
            craftResult = { type: recipe.result.type, count: recipe.result.count, durability: recipe.result.durability || 0 };
            break;
        }
    }
}

function claimCraftResult() {
    if (craftResult.type === BLOCKS.AIR || craftResult.count <= 0) return;
    if (!heldItem || heldItem.count === 0 || heldItem.type === BLOCKS.AIR) { heldItem = { ...craftResult }; }
    else if (heldItem.type === craftResult.type) { heldItem.count += craftResult.count; }
    else { return notify("Clear hand first!"); }

    for (let i = 0; i < 9; i++) {
        if (craftingGrid[i].type !== BLOCKS.AIR) {
            craftingGrid[i].count--;
            if (craftingGrid[i].count <= 0) craftingGrid[i] = emptySlot();
        }
    }
    checkCraftingRecipe();
    renderCraftingGrid();
    updateUI();
}

function renderSlotInElement(el, slot) {
    el.innerHTML = '';
    if (!slot || slot.type === BLOCKS.AIR || slot.count <= 0) return;
    const prop = BLOCK_PROPS[slot.type];
    const cvs = generateItemIcon(slot.type);
    const img = document.createElement('img');
    img.src = cvs.toDataURL();
    img.className = 'item-icon-canvas';
    img.title = (prop.name || '') + (slot.count > 1 ? ' x' + slot.count : '');
    el.appendChild(img);
    el.title = img.title;

    if (prop.itemType !== ITEM_TYPES.TOOL && prop.itemType !== ITEM_TYPES.ARMOR && slot.count > 1) {
        const countEl = document.createElement('div');
        countEl.className = 'slot-count';
        countEl.innerText = slot.count;
        el.appendChild(countEl);
    }

    if (prop.itemType === ITEM_TYPES.TOOL && slot.durability > 0) {
        const durBar = document.createElement('div');
        durBar.className = 'durability-bar';
        const fill = document.createElement('div');
        fill.className = 'durability-fill';
        const maxDur = prop.maxDurability || 1;
        const ratio = slot.durability / maxDur;
        fill.style.width = Math.floor(ratio * 100) + '%';
        fill.style.background = ratio > 0.4 ? '#2ecc71' : (ratio > 0.15 ? '#f39c12' : '#e74c3c');
        durBar.appendChild(fill);
        el.appendChild(durBar);
    }
}

function updateUI() {
    const invEl = document.getElementById('inventory');
    invEl.innerHTML = '';
    hotbar.forEach((slot, idx) => {
        const el = document.createElement('div');
        el.className = 'slot' + (idx === selectedSlot ? ' selected' : '');
        const keyEl = document.createElement('div');
        keyEl.className = 'slot-key';
        keyEl.innerText = idx + 1;
        el.appendChild(keyEl);
        renderSlotInElement(el, slot);
        el.onclick = () => {
            const invOpen = document.getElementById('inventory-modal').style.display === 'flex';
            if (invOpen) { handleSlotClick(hotbar, idx); renderInventoryModal(); }
            else { selectedSlot = idx; }
            updateUI();
        };
        invEl.appendChild(el);
    });
}

function renderInventoryModal() {
    const storeEl = document.getElementById('storage-grid');
    storeEl.innerHTML = '';
    inventoryStorage.forEach((slot, idx) => {
        const el = document.createElement('div');
        el.className = 'slot';
        renderSlotInElement(el, slot);
        el.onclick = () => { handleSlotClick(inventoryStorage, idx); renderInventoryModal(); updateUI(); };
        storeEl.appendChild(el);
    });
    ['head', 'chest', 'legs', 'feet'].forEach(key => {
        const el = document.getElementById('equip-slot-' + key);
        renderSlotInElement(el, equipment[key]);
        el.onclick = () => handleEquipClick(key);
    });
    document.getElementById('defense-display').innerText = 'Defense: ' + getTotalDefense() + '%';
}

function toggleInventoryModal(forceClose) {
    const modal = document.getElementById('inventory-modal');
    const backdrop = document.getElementById('inventory-backdrop');
    if (!modal) return;
    const isVis = modal.style.display === 'flex';
    if (forceClose || isVis) {
        modal.style.display = 'none';
        backdrop.style.display = 'none';
    } else {
        modal.style.display = 'flex';
        backdrop.style.display = 'block';
        renderInventoryModal();
    }
}

// ============================================================================
// WORLDS - Firestore-backed (users/{uid}/worldsMeta + worldsData)
// ============================================================================
function getWorldSnapshot() {
    return {
        world: Array.from(world), wallWorld: Array.from(wallWorld), player: player,
        hotbar, inventoryStorage, equipment, gameMode, worldSeed
    };
}
function applyWorldSnapshot(data) {
    world = new Uint8Array(data.world);
    wallWorld = new Uint8Array(data.wallWorld);
    blockDamageMap.fill(0);
    if (data.hotbar) hotbar = data.hotbar;
    if (data.inventoryStorage) inventoryStorage = data.inventoryStorage;
    if (data.equipment) equipment = data.equipment;
    if (data.player) player = { ...player, ...data.player, health: (data.player.health != null ? data.player.health : 100) };
    if (data.gameMode) { gameMode = data.gameMode; document.getElementById('game-mode').value = gameMode; }
}
function loadWorldSnapshot(data) {
    try {
        if (!data.world || data.world.length !== WORLD_WIDTH * WORLD_HEIGHT) throw new Error('Bad dimensions');
        applyWorldSnapshot(data);
        notify('World Loaded!');
        closeWorldsModal();
        startEngineUI();
    } catch (err) { notify('Could not load that world.'); }
}
async function saveCurrentWorldToAccount(existingId) {
    if (document.getElementById('ui-layer').style.display === 'none') { notify('Start a world first!'); return; }
    if (!requireOnline()) return;
    const nameInput = document.getElementById('world-save-name');
    const name = (nameInput && nameInput.value.trim()) || ('World ' + new Date().toLocaleString());
    const snapshot = getWorldSnapshot();
    const ts = Date.now();
    try {
        if (existingId) {
            await worldsDataColRef().doc(existingId).set(snapshot);
            await worldsMetaColRef().doc(existingId).set({ name, ts }, { merge: true });
            const meta = worldsMetaCache.find(w => w.id === existingId);
            if (meta) { meta.name = name; meta.ts = ts; }
            activeCloudWorldId = existingId;
        } else {
            const metaRef = await worldsMetaColRef().add({ name, ts });
            await worldsDataColRef().doc(metaRef.id).set(snapshot);
            worldsMetaCache.unshift({ id: metaRef.id, name, ts });
            activeCloudWorldId = metaRef.id;
        }
        if (nameInput) nameInput.value = '';
        notify('World saved to your account!');
        renderWorldsModalList();
        renderAccountWorldsList();
    } catch (e) { notify('Could not save world: ' + e.message); }
}
async function loadWorldFromAccount(worldId) {
    if (!requireOnline()) return;
    try {
        const snap = await worldsDataColRef().doc(worldId).get();
        if (!snap.exists) return notify('World data not found.');
        activeCloudWorldId = worldId;
        loadWorldSnapshot(snap.data());
    } catch (e) { notify('Could not load world: ' + e.message); }
}
async function deleteAccountWorld(worldId) {
    try {
        await worldsMetaColRef().doc(worldId).delete();
        await worldsDataColRef().doc(worldId).delete();
        worldsMetaCache = worldsMetaCache.filter(w => w.id !== worldId);
        if (activeCloudWorldId === worldId) activeCloudWorldId = null;
        renderWorldsModalList(); renderAccountWorldsList();
    } catch (e) { notify('Could not delete: ' + e.message); }
}
function openWorldsModal() {
    renderWorldsModalList();
    document.getElementById('world-save-current-btn').style.display = activeCloudWorldId ? 'inline-flex' : 'none';
    document.getElementById('worlds-modal').style.display = 'flex';
    document.getElementById('worlds-backdrop').style.display = 'block';
}
function closeWorldsModal() {
    const modal = document.getElementById('worlds-modal');
    if (modal) modal.style.display = 'none';
    const backdrop = document.getElementById('worlds-backdrop');
    if (backdrop) backdrop.style.display = 'none';
}
function renderWorldsModalList() {
    const el = document.getElementById('worlds-modal-list');
    if (!el) return;
    el.innerHTML = '';
    if (!firebaseReady) { el.innerHTML = '<div style="font-size:7px;color:#888;">Cloud saves need Firebase configured.</div>'; return; }
    if (worldsMetaCache.length === 0) { el.innerHTML = '<div style="font-size:7px;color:#888;">No saved worlds yet.</div>'; return; }
    worldsMetaCache.forEach(w => {
        const row = document.createElement('div'); row.className = 'saved-list-row';
        const label = document.createElement('div'); label.className = 'name';
        label.innerText = w.name + (w.id === activeCloudWorldId ? ' (current)' : '') + ' - ' + new Date(w.ts).toLocaleDateString();
        row.appendChild(label);
        const loadBtn = document.createElement('button'); loadBtn.className = 'pixel-btn small'; loadBtn.innerText = 'Load';
        loadBtn.onclick = () => { if (confirm('Load "' + w.name + '"? Unsaved progress will be lost.')) loadWorldFromAccount(w.id); };
        row.appendChild(loadBtn);
        const delBtn = document.createElement('button'); delBtn.className = 'pixel-btn danger small'; delBtn.innerText = 'X';
        delBtn.onclick = () => deleteAccountWorld(w.id);
        row.appendChild(delBtn);
        el.appendChild(row);
    });
}
function renderAccountWorldsList() {
    const el = document.getElementById('account-worlds-list');
    if (!el) return;
    el.innerHTML = '';
    if (!firebaseReady) { el.innerHTML = '<div style="font-size:7px;color:#888;">Cloud saves need Firebase configured.</div>'; return; }
    if (worldsMetaCache.length === 0) { el.innerHTML = '<div style="font-size:7px;color:#888;">No saved worlds yet - play, then use "Worlds" in-game.</div>'; return; }
    worldsMetaCache.forEach(w => {
        const row = document.createElement('div'); row.className = 'saved-list-row';
        const label = document.createElement('div'); label.className = 'name';
        label.innerText = w.name + ' - ' + new Date(w.ts).toLocaleDateString();
        row.appendChild(label);
        const loadBtn = document.createElement('button'); loadBtn.className = 'pixel-btn small'; loadBtn.innerText = 'Play';
        loadBtn.onclick = () => loadWorldFromAccount(w.id);
        row.appendChild(loadBtn);
        const delBtn = document.createElement('button'); delBtn.className = 'pixel-btn danger small'; delBtn.innerText = 'X';
        delBtn.onclick = () => deleteAccountWorld(w.id);
        row.appendChild(delBtn);
        el.appendChild(row);
    });
}
function renderHostWorldOptions() {
    const sel = document.getElementById('host-world-select');
    if (!sel) return;
    sel.innerHTML = '<option value="__new__">New World</option>';
    worldsMetaCache.forEach(w => {
        const opt = document.createElement('option');
        opt.value = w.id; opt.innerText = w.name;
        sel.appendChild(opt);
    });
}

// MAIN MENU ENGINE CONTROLS
function startSingleplayer() {
    const seedVal = document.getElementById('world-seed').value;
    gameMode = document.getElementById('game-mode').value;
    document.getElementById('mode-display').innerText = gameMode.toUpperCase();
    activeCloudWorldId = null;
    resetHotbar();
    generateContinuousCaves(seedVal ? hashString(seedVal) : Math.random() * 10000);
    startEngineUI();
    notify("Punch trees for Wood! Craft a Bench to get started.");
}

function hashString(str) {
    let hash = 0;
    for (let i = 0; i < str.length; i++) { hash = (hash << 5) - hash + str.charCodeAt(i); hash |= 0; }
    return Math.abs(hash);
}

function startEngineUI() {
    document.getElementById('menu-overlay').style.display = 'none';
    document.getElementById('ui-layer').style.display = 'flex';
    document.getElementById('mode-display').innerText = isMultiplayer ? ('MULTIPLAYER - ' + gameMode.toUpperCase()) : gameMode.toUpperCase();
    document.getElementById('player-count-display').style.display = isMultiplayer ? 'inline-flex' : 'none';
    updateUI();
    updateHealthUI();
    updatePlayerCountDisplay();
    lastFrameTime = performance.now();
    requestAnimationFrame(gameLoop);
}

function leaveToMenu() {
    if (isHost) cleanupHost();
    else if (isMultiplayer) cleanupClient();
    isMultiplayer = false; isHost = false;
    otherPlayers.clear();
    document.getElementById('ui-layer').style.display = 'none';
    document.getElementById('menu-overlay').style.display = 'flex';
    showMenuSection('main-menu');
}

function downloadWorldJSON() {
    const blob = new Blob([JSON.stringify(getWorldSnapshot())], { type: 'application/json' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'pixel_world.json';
    a.click();
}

function loadWorldFromFile(e) {
    const file = e.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function (evt) {
        try { const data = JSON.parse(evt.target.result); activeCloudWorldId = null; loadWorldSnapshot(data); }
        catch (err) { notify("Invalid World File!"); }
    };
    reader.readAsText(file);
}

// ============================================================================
// MULTIPLAYER - raw WebRTC (RTCPeerConnection + RTCDataChannel), signaled
// through Firestore. No third-party PeerJS broker involved.
//
// Firestore layout per room:
//   servers/{roomCode}                                   room metadata
//   servers/{roomCode}/callers/{callerId}                 offer + answer
//   servers/{roomCode}/callers/{callerId}/callerCandidates/{id}   client -> host ICE
//   servers/{roomCode}/callers/{callerId}/hostCandidates/{id}     host -> client ICE
// ============================================================================
const ICE_CONFIG = {
    iceServers: [
        { urls: 'stun:stun.l.google.com:19302' },
        { urls: 'stun:stun1.l.google.com:19302' },
        { urls: 'stun:stun2.l.google.com:19302' },
        { urls: 'stun:stun3.l.google.com:19302' },
        { urls: 'turn:openrelay.metered.ca:80', username: 'openrelayproject', credential: 'openrelayproject' },
        { urls: 'turn:openrelay.metered.ca:443', username: 'openrelayproject', credential: 'openrelayproject' },
        { urls: 'turn:openrelay.metered.ca:443?transport=tcp', username: 'openrelayproject', credential: 'openrelayproject' }
    ]
};

let currentRoomCode = null;
let hostConnections = {};   // callerId -> { pc, dc, helloReceived, candUnsub }
let callersUnsub = null;
let heartbeatInterval = null, autosaveInterval = null;

let clientPC = null, clientDC = null, currentCallerId = null, joinCleanupFns = null;

function generateRoomCode() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    let code = '';
    for (let i = 0; i < 5; i++) code += chars[Math.floor(Math.random() * chars.length)];
    return 'PXL-' + code;
}

function copyRoomCode() {
    if (currentRoomCode) navigator.clipboard.writeText(currentRoomCode).then(() => notify('Code copied!')).catch(() => notify('Could not copy - select manually.'));
}

function currentPlayerName() { return document.getElementById('player-name').value || 'Player'; }

// ---- HOST SIDE ----
async function hostMultiplayerRoom() {
    if (!requireOnline()) return;
    isMultiplayer = true; isHost = true;
    document.getElementById('host-room-btn').disabled = true;
    const box = document.getElementById('host-code-box');
    box.style.display = 'block'; box.innerText = 'SETTING UP...';

    const roomCode = generateRoomCode();
    const chosenWorldId = document.getElementById('host-world-select').value;

    resetHotbar();
    activeCloudWorldId = null;
    if (chosenWorldId && chosenWorldId !== '__new__') {
        try {
            const snap = await worldsDataColRef().doc(chosenWorldId).get();
            if (snap.exists) { applyWorldSnapshot(snap.data()); activeCloudWorldId = chosenWorldId; }
            else generateContinuousCaves();
        } catch (e) { generateContinuousCaves(); }
    } else {
        generateContinuousCaves();
    }

    currentRoomCode = roomCode;
    hostConnections = {};

    const worldMeta = worldsMetaCache.find(w => w.id === activeCloudWorldId);
    try {
        await db.collection('servers').doc(roomCode).set({
            hostUid: currentUser.uid,
            hostName: currentPlayerName(),
            worldName: worldMeta ? worldMeta.name : 'New World',
            mode: gameMode,
            players: 1,
            listed: document.getElementById('host-list-public').checked,
            createdAt: firebase.firestore.FieldValue.serverTimestamp(),
            updatedAt: firebase.firestore.FieldValue.serverTimestamp()
        });
    } catch (e) {
        notify('Could not create room: ' + e.message);
        document.getElementById('host-room-btn').disabled = false;
        isMultiplayer = false; isHost = false;
        return;
    }

    box.innerText = 'ROOM CODE: ' + roomCode;
    document.getElementById('copy-code-btn').style.display = 'inline-block';
    document.getElementById('start-hosted-btn').style.display = 'inline-block';
    notify('Room ready! Share the code with friends.');

    listenForCallers(roomCode);
    startHostAutosave();
}

function listenForCallers(roomCode) {
    callersUnsub = db.collection('servers').doc(roomCode).collection('callers').onSnapshot(snapshot => {
        snapshot.docChanges().forEach(change => {
            if (change.type === 'added') handleNewCaller(roomCode, change.doc.id, change.doc.data());
        });
    }, err => notify('Signaling error: ' + err.message));
}

async function handleNewCaller(roomCode, callerId, data) {
    if (hostConnections[callerId] || !data.offer) return;
    const pc = new RTCPeerConnection(ICE_CONFIG);
    const entry = { pc, dc: null, helloReceived: false, candUnsub: null };
    hostConnections[callerId] = entry;

    const callerDocRef = db.collection('servers').doc(roomCode).collection('callers').doc(callerId);

    pc.onicecandidate = e => { if (e.candidate) callerDocRef.collection('hostCandidates').add(e.candidate.toJSON()).catch(() => {}); };
    pc.ondatachannel = e => { entry.dc = e.channel; wireHostDataChannel(entry, callerId); };
    pc.onconnectionstatechange = () => {
        if (pc.connectionState === 'failed' || pc.connectionState === 'closed') removeHostConnection(callerId);
    };

    entry.candUnsub = callerDocRef.collection('callerCandidates').onSnapshot(snap => {
        snap.docChanges().forEach(ch => { if (ch.type === 'added') pc.addIceCandidate(new RTCIceCandidate(ch.doc.data())).catch(() => {}); });
    });

    try {
        await pc.setRemoteDescription(new RTCSessionDescription(data.offer));
        const answer = await pc.createAnswer();
        await pc.setLocalDescription(answer);
        await callerDocRef.update({ answer: { type: answer.type, sdp: answer.sdp } });
    } catch (e) {
        notify('Failed to answer a join request.');
        removeHostConnection(callerId);
    }
}

function wireHostDataChannel(entry, callerId) {
    const dc = entry.dc;
    dc.onmessage = ev => {
        let data; try { data = JSON.parse(ev.data); } catch (e) { return; }
        if (data.type === 'HELLO') {
            entry.helloReceived = true;
            notify((data.name || 'A player') + ' connected!');
            sendWorldTo(entry);
            updateHostPlayerCount();
        } else {
            handleNetworkData(data, callerId);
        }
    };
    dc.onclose = () => { removeHostConnection(callerId); notify('A player disconnected.'); };
    dc.onerror = () => notify('A connection error occurred.');
    setTimeout(() => { if (!entry.helloReceived && dc.readyState === 'open') sendWorldTo(entry); }, 1500);
}

function removeHostConnection(callerId) {
    const entry = hostConnections[callerId];
    if (!entry) return;
    if (entry.candUnsub) entry.candUnsub();
    if (entry.dc) try { entry.dc.close(); } catch (e) {}
    if (entry.pc) try { entry.pc.close(); } catch (e) {}
    delete hostConnections[callerId];
    otherPlayers.delete(callerId);
    updateHostPlayerCount();
}

function sendWorldTo(entry) {
    if (!entry.dc || entry.dc.readyState !== 'open') return;
    try { entry.dc.send(JSON.stringify({ type: 'WORLD', world: Array.from(world), wallWorld: Array.from(wallWorld) })); }
    catch (e) { setTimeout(() => sendWorldTo(entry), 1000); }
}

function updateHostPlayerCount() {
    updatePlayerCountDisplay();
    if (currentRoomCode && firebaseReady) {
        const count = Object.values(hostConnections).filter(e => e.dc && e.dc.readyState === 'open').length + 1;
        db.collection('servers').doc(currentRoomCode).update({ players: count, updatedAt: firebase.firestore.FieldValue.serverTimestamp() }).catch(() => {});
    }
}

function startHostAutosave() {
    if (autosaveInterval) clearInterval(autosaveInterval);
    if (heartbeatInterval) clearInterval(heartbeatInterval);
    autosaveInterval = setInterval(() => {
        if (activeCloudWorldId && firebaseReady) worldsDataColRef().doc(activeCloudWorldId).set(getWorldSnapshot()).catch(() => {});
    }, 45000);
    heartbeatInterval = setInterval(() => {
        if (currentRoomCode && firebaseReady) {
            const count = Object.values(hostConnections).filter(e => e.dc && e.dc.readyState === 'open').length + 1;
            db.collection('servers').doc(currentRoomCode).update({ updatedAt: firebase.firestore.FieldValue.serverTimestamp(), players: count }).catch(() => {});
        }
    }, 20000);
}

function cleanupHost() {
    if (callersUnsub) { callersUnsub(); callersUnsub = null; }
    Object.keys(hostConnections).forEach(removeHostConnection);
    hostConnections = {};
    if (heartbeatInterval) { clearInterval(heartbeatInterval); heartbeatInterval = null; }
    if (autosaveInterval) { clearInterval(autosaveInterval); autosaveInterval = null; }
    if (currentRoomCode && firebaseReady) db.collection('servers').doc(currentRoomCode).delete().catch(() => {});
    currentRoomCode = null;
    document.getElementById('host-room-btn').disabled = false;
}

// ---- CLIENT (JOINER) SIDE ----
async function joinMultiplayerRoom() {
    if (!requireOnline()) return;
    const code = document.getElementById('join-room-code').value.trim().toUpperCase();
    if (!code) return notify('Enter host code!');

    isMultiplayer = true; isHost = false;
    document.getElementById('join-room-btn').disabled = true;
    notify('Looking up server...');

    const roomRef = db.collection('servers').doc(code);
    let roomSnap;
    try { roomSnap = await roomRef.get(); }
    catch (e) { notify('Could not reach the server: ' + e.message); document.getElementById('join-room-btn').disabled = false; isMultiplayer = false; return; }

    if (!roomSnap.exists) {
        notify('No server found with that code.');
        document.getElementById('join-room-btn').disabled = false; isMultiplayer = false; return;
    }
    const roomData = roomSnap.data();
    if (roomData.updatedAt && (Date.now() - roomData.updatedAt.toMillis()) > 90000) {
        notify('This server appears to be offline (no recent heartbeat).');
        document.getElementById('join-room-btn').disabled = false; isMultiplayer = false; return;
    }

    notify('Connecting...');
    const pc = new RTCPeerConnection(ICE_CONFIG);
    clientPC = pc;
    const dc = pc.createDataChannel('game');
    clientDC = dc;
    currentRoomCode = code;

    const callerDocRef = roomRef.collection('callers').doc();
    currentCallerId = callerDocRef.id;

    let worldReceived = false;
    let helloTimer = null;

    pc.onicecandidate = e => { if (e.candidate) callerDocRef.collection('callerCandidates').add(e.candidate.toJSON()).catch(() => {}); };

    const hostCandUnsub = callerDocRef.collection('hostCandidates').onSnapshot(snap => {
        snap.docChanges().forEach(ch => { if (ch.type === 'added') pc.addIceCandidate(new RTCIceCandidate(ch.doc.data())).catch(() => {}); });
    });
    const answerUnsub = callerDocRef.onSnapshot(snap => {
        const data = snap.data();
        if (data && data.answer && !pc.currentRemoteDescription) {
            pc.setRemoteDescription(new RTCSessionDescription(data.answer)).catch(() => {});
        }
    });

    function sendHello() {
        if (dc.readyState === 'open') dc.send(JSON.stringify({ type: 'HELLO', name: currentPlayerName() }));
        if (!worldReceived) helloTimer = setTimeout(sendHello, 2500);
    }

    dc.onopen = () => { notify('Connected! Requesting world...'); sendHello(); };
    dc.onmessage = ev => {
        let data; try { data = JSON.parse(ev.data); } catch (e) { return; }
        if (data.type === 'WORLD') {
            worldReceived = true;
            clearTimeout(helloTimer);
            clearTimeout(timeoutId);
            resetHotbar();
            applyWorldSnapshot({ world: data.world, wallWorld: data.wallWorld });
            spawnPlayer();
            player.health = player.maxHealth;
            notify('World received!');
            startEngineUI();
        } else {
            handleNetworkData(data, data.__relayFrom || 'host');
        }
    };
    dc.onclose = () => notify('Disconnected from host.');
    dc.onerror = () => notify('Connection error.');

    joinCleanupFns = () => { hostCandUnsub(); answerUnsub(); clearTimeout(helloTimer); clearTimeout(timeoutId); };

    try {
        const offer = await pc.createOffer();
        await pc.setLocalDescription(offer);
        await callerDocRef.set({ offer: { type: offer.type, sdp: offer.sdp }, name: currentPlayerName(), createdAt: firebase.firestore.FieldValue.serverTimestamp() });
    } catch (e) {
        notify('Could not start connection: ' + e.message);
        document.getElementById('join-room-btn').disabled = false;
        cleanupClient(); isMultiplayer = false;
        return;
    }

    var timeoutId = setTimeout(() => {
        if (!worldReceived) {
            notify('Connection timed out. The host may be offline or unreachable.');
            document.getElementById('join-room-btn').disabled = false;
            cleanupFailedJoin();
        }
    }, 20000);
}

function cleanupClient() {
    if (joinCleanupFns) { joinCleanupFns(); joinCleanupFns = null; }
    if (clientPC) { try { clientPC.close(); } catch (e) {} clientPC = null; }
    clientDC = null;
    if (currentRoomCode && currentCallerId && firebaseReady) {
        db.collection('servers').doc(currentRoomCode).collection('callers').doc(currentCallerId).delete().catch(() => {});
    }
    currentRoomCode = null; currentCallerId = null;
    document.getElementById('join-room-btn').disabled = false;
}
function cleanupFailedJoin() { cleanupClient(); isMultiplayer = false; }

// ---- SHARED MESSAGE BROADCASTING ----
function sendToAllClients(msg, exceptId) {
    const payload = JSON.stringify(msg);
    Object.keys(hostConnections).forEach(id => {
        if (id === exceptId) return;
        const e = hostConnections[id];
        if (e.dc && e.dc.readyState === 'open') e.dc.send(payload);
    });
}

function broadcastPlayerPos() {
    if (!isMultiplayer) return;
    const msg = {
        type: 'POS',
        name: currentPlayerName(),
        x: player.x, y: player.y, facing: player.facing, animTime: player.animTime,
        skin: currentSkin,
        armor: { head: equipment.head.type, chest: equipment.chest.type, legs: equipment.legs.type, feet: equipment.feet.type }
    };
    if (isHost) sendToAllClients(msg);
    else if (clientDC && clientDC.readyState === 'open') clientDC.send(JSON.stringify(msg));
}

function broadcastBlockChange(col, row, blockType) {
    if (!isMultiplayer) return;
    const msg = { type: 'BLOCK', col, row, blockType };
    if (isHost) sendToAllClients(msg);
    else if (clientDC && clientDC.readyState === 'open') clientDC.send(JSON.stringify(msg));
}

function handleNetworkData(data, senderKey) {
    if (data.type === 'POS') {
        otherPlayers.set(senderKey, data);
        if (isHost) sendToAllClients({ ...data, __relayFrom: senderKey }, senderKey);
    } else if (data.type === 'BLOCK') {
        const index = data.row * WORLD_WIDTH + data.col;
        world[index] = data.blockType;
        blockDamageMap[index] = 0;
        if (isHost) sendToAllClients(data, senderKey);
    }
}

window.addEventListener('beforeunload', () => {
    if (isHost) cleanupHost();
    else if (isMultiplayer) cleanupClient();
});

// ---- SERVER BROWSER (live Firestore query) ----
let serverBrowserUnsub = null;
let serverBrowserCache = [];
function startServerBrowserListener() {
    stopServerBrowserListener();
    if (!requireOnline()) { renderServerBrowser(); return; }
    serverBrowserUnsub = db.collection('servers').where('listed', '==', true).limit(30).onSnapshot(snap => {
        serverBrowserCache = snap.docs.map(d => ({ id: d.id, ...d.data() }));
        renderServerBrowser();
    }, err => { notify('Server list error: ' + err.message); });
}
function stopServerBrowserListener() { if (serverBrowserUnsub) { serverBrowserUnsub(); serverBrowserUnsub = null; } }
function renderServerBrowser() {
    const el = document.getElementById('server-browser-list');
    if (!el) return;
    el.innerHTML = '';
    if (!firebaseReady) { el.innerHTML = '<div style="font-size:7px;color:#888;">Multiplayer needs Firebase configured.</div>'; return; }
    const list = [...serverBrowserCache].sort((a, b) => ((b.updatedAt && b.updatedAt.seconds) || 0) - ((a.updatedAt && a.updatedAt.seconds) || 0));
    const fresh = list.filter(info => !info.updatedAt || (Date.now() - info.updatedAt.toMillis()) < 90000);
    if (fresh.length === 0) { el.innerHTML = '<div style="font-size:7px;color:#888;">No public rooms right now.</div>'; return; }
    fresh.forEach(info => {
        const row = document.createElement('div'); row.className = 'saved-list-row';
        const label = document.createElement('div'); label.className = 'name';
        label.innerText = (info.worldName || 'Room') + ' by ' + (info.hostName || '?') + ' - ' + info.id + ' [' + (info.mode || 'survival') + ', ' + (info.players || 1) + 'p]';
        row.appendChild(label);
        const joinBtn = document.createElement('button'); joinBtn.className = 'pixel-btn small'; joinBtn.innerText = 'Join';
        joinBtn.onclick = () => { document.getElementById('join-room-code').value = info.id; joinMultiplayerRoom(); };
        row.appendChild(joinBtn);
        el.appendChild(row);
    });
}

// MAIN ENGINE LOOP
function gameLoop(now) {
    const dt = now - lastFrameTime;
    lastFrameTime = now;
    updatePhysics(dt);
    render(dt);
    frameCounter++;
    if (frameCounter % 15 === 0) renderMinimap();
    if (frameCounter % 60 === 0 && isMultiplayer) updatePlayerCountDisplay();
    if (frameCounter % 300 === 0 && player.health < player.maxHealth) { player.health = Math.min(player.maxHealth, player.health + 2); updateHealthUI(); }
    requestAnimationFrame(gameLoop);
}

// MENU BACKGROUND - a live, auto-scrolling example world rendered behind the main menu
const DEMO_W = 110, DEMO_H = 60;
let demoWorld = new Uint8Array(DEMO_W * DEMO_H);
let demoWall = new Uint8Array(DEMO_W * DEMO_H);
let demoCamX = 0;

function generateDemoWorld() {
    const seed = Math.random() * 10000;
    demoWorld.fill(BLOCKS.AIR);
    demoWall.fill(BLOCKS.AIR);
    for (let x = 0; x < DEMO_W; x++) {
        const biomeNoise = pseudoNoise(x * 0.02, 0, seed + 500);
        const isDesert = biomeNoise < 0.25;
        let surfaceHeight = Math.floor(Math.sin(x * 0.09 + seed) * 6) + 22;
        for (let y = 0; y < DEMO_H; y++) {
            const index = y * DEMO_W + x;
            if (y > surfaceHeight) {
                demoWall[index] = BLOCKS.DIRT;
                const c1 = Math.sin(x * 0.1 + y * 0.06 + seed);
                const c2 = Math.cos(x * 0.06 - y * 0.1 + seed * 2);
                const wormVal = Math.abs(c1 * c2);
                if (wormVal > 0.22 || y <= surfaceHeight + 2) {
                    if (y > surfaceHeight + 10) {
                        const ore = pseudoNoise(x * 0.1, y * 0.1, seed);
                        if (ore < 0.05) demoWorld[index] = BLOCKS.GOLD;
                        else if (ore < 0.12) demoWorld[index] = BLOCKS.IRON;
                        else if (ore < 0.2) demoWorld[index] = BLOCKS.COAL;
                        else demoWorld[index] = (y > surfaceHeight + 35) ? BLOCKS.DEEP_STONE : BLOCKS.STONE;
                    } else demoWorld[index] = (isDesert) ? BLOCKS.SAND : BLOCKS.DIRT;
                }
            } else if (y === surfaceHeight) {
                if (isDesert) { demoWorld[index] = BLOCKS.SAND; demoWall[index] = BLOCKS.SAND; }
                else {
                    demoWorld[index] = BLOCKS.GRASS; demoWall[index] = BLOCKS.DIRT;
                    if (x > 3 && x < DEMO_W - 3 && pseudoNoise(x, 1, seed) < 0.15) {
                        const th = 3 + Math.floor(Math.random() * 2);
                        for (let i = 0; i < th; i++) { const ty = surfaceHeight - 1 - i; if (ty >= 0) demoWorld[ty * DEMO_W + x] = BLOCKS.WOOD; }
                        const leafTop = surfaceHeight - 1 - th;
                        for (let lx = -1; lx <= 1; lx++) {
                            for (let ly = -1; ly <= 1; ly++) {
                                const li = (leafTop + ly) * DEMO_W + (x + lx);
                                if (li >= 0 && li < demoWorld.length && demoWorld[li] === BLOCKS.AIR) demoWorld[li] = BLOCKS.LEAVES;
                            }
                        }
                    }
                }
            }
        }
    }
}
generateDemoWorld();

function menuBackgroundLoop(now) {
    const overlay = document.getElementById('menu-overlay');
    if (overlay.style.display === 'none') return;

    const dt = now - (menuBackgroundLoop.last || now);
    menuBackgroundLoop.last = now;

    dayCycle += dt * 0.000012;
    const dayLight = (Math.sin(dayCycle) + 1) / 2;

    const skyTopR = Math.floor(15 + dayLight * 115);
    const skyTopG = Math.floor(15 + dayLight * 175);
    const skyTopB = Math.floor(30 + dayLight * 225);
    const grad = ctx.createLinearGradient(0, 0, 0, canvas.height);
    grad.addColorStop(0, `rgb(${skyTopR}, ${skyTopG}, ${skyTopB})`);
    grad.addColorStop(1, `rgb(${Math.floor(skyTopR * 0.6)}, ${Math.floor(skyTopG * 0.6)}, ${Math.floor(skyTopB * 0.6)})`);
    ctx.fillStyle = grad;
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    drawCelestial(dayLight);
    drawParallaxMountains(dayLight);

    demoCamX += dt * 0.018;
    const worldPxW = DEMO_W * TILE_SIZE;
    if (demoCamX > worldPxW - canvas.width) demoCamX = 0;
    const demoCamY = Math.max(0, (DEMO_H * TILE_SIZE - canvas.height) / 2);

    const startCol = Math.max(0, Math.floor(demoCamX / TILE_SIZE));
    const endCol = Math.min(DEMO_W, Math.ceil((demoCamX + canvas.width) / TILE_SIZE));
    const startRow = Math.max(0, Math.floor(demoCamY / TILE_SIZE));
    const endRow = Math.min(DEMO_H, Math.ceil((demoCamY + canvas.height) / TILE_SIZE));

    for (let r = startRow; r < endRow; r++) {
        for (let c = startCol; c < endCol; c++) {
            const index = r * DEMO_W + c;
            const block = demoWorld[index];
            const wall = demoWall[index];
            const x = Math.floor(c * TILE_SIZE - demoCamX);
            const y = Math.floor(r * TILE_SIZE - demoCamY);
            if (block === BLOCKS.AIR && wall !== BLOCKS.AIR) { ctx.fillStyle = '#1c1310'; ctx.fillRect(x, y, TILE_SIZE, TILE_SIZE); }
            else if (block !== BLOCKS.AIR) ctx.drawImage(generateBlockTexture(block), x, y);
        }
    }

    drawVignette();
    requestAnimationFrame(menuBackgroundLoop);
}
requestAnimationFrame(menuBackgroundLoop);
</script>
</body>
</html>
