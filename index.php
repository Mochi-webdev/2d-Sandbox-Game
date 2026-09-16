<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pixel Sandbox Engine</title>
    <link href="https://fonts.googleapis.com/css2?family=Press+Start+2P&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <script src="https://www.gstatic.com/firebasejs/10.14.1/firebase-app-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/10.14.1/firebase-auth-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/10.14.1/firebase-firestore-compat.js"></script>
</head>
<body>
<div id="game-container">
    <div id="notification"></div>
    <canvas id="dragged-item" class="item-icon-canvas"></canvas>
    <canvas id="gameCanvas"></canvas>

    <div id="ui-layer" style="display: none;">
        <div class="hud-top">
            <div style="display:flex; gap:10px; align-items: flex-start; flex-wrap: wrap;">
                <button class="pixel-btn" onclick="openBasicCrafting()">Basic Craft (C)</button>
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

        <div id="crafting-backdrop" onclick="closeCraftingModal()"></div>
        <div id="crafting-modal">
            <div class="crafting-close-btn" onclick="closeCraftingModal()">X</div>
            <div class="crafting-container">
                <div id="crafting-title" style="color: var(--pixel-gold-bright); font-size: 9px; text-shadow: 1px 1px 0 #000;">BASIC CRAFTING</div>
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

        <div id="chest-backdrop" class="game-backdrop" onclick="closeChestModal()"></div>
        <div id="chest-modal" class="game-modal">
            <h3>CHEST</h3>
            <div class="storage-grid" id="chest-grid" style="grid-template-columns: repeat(9, 48px);"></div>
            <div style="font-size:7px; color:#a0a0c0; text-align:center;">Click slots to move items. Works with your hotbar and inventory.</div>
            <button class="pixel-btn danger" onclick="closeChestModal()">Close</button>
        </div>

        <div class="bottom-ui">
            <div class="health-row">
                <span class="health-label">HP</span>
                <div class="health-bar-container"><div id="health-bar-fill"></div></div>
            </div>
            <div class="pixel-panel" id="inventory"></div>
            <div class="action-tip-box">LMB mine / RMB place • C basic craft • Click Bench / Furnace / Chest • E inventory</div>
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
            <div style="font-size:8px; color:var(--pixel-gold-bright); margin-top:6px;">REHOST PREVIOUS WORLDS</div>
            <div id="rehost-list" style="max-height:90px; overflow-y:auto;"></div>
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
<script src="js/config.js"></script>
<script src="js/world.js"></script>
<script src="js/rendering.js"></script>
<script src="js/inventory.js"></script>
<script src="js/ui.js"></script>
<script src="js/multiplayer.js"></script>
<script src="js/game.js"></script>
</body>
</html>
