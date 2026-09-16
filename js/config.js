/* ============================================================
   config.js – Constants, blocks, recipes, Firebase config
   ============================================================ */

const TILE_SIZE = 32;
const WORLD_WIDTH = 200;
const WORLD_HEIGHT = 100;
const INTERACTION_RANGE = 4.5 * TILE_SIZE;
const HAND_POWER = 1.0;
const CHEST_SIZE = 27;

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
    GOLD_HELMET: 26, GOLD_CHEST: 27, GOLD_LEGS: 28, GOLD_BOOTS: 29,
    IRON_SWORD: 30, CHEST: 31,
    // New blocks
    COPPER: 32, COPPER_INGOT: 33, COPPER_PICKAXE: 34,
    CRYSTAL: 35, CRYSTAL_SHARD: 36,
    SNOW: 37, ICE: 38, SNOW_GRASS: 39,
    MOSSY_STONE: 40, MUSHROOM: 41,
    CAVE_BRICK: 42, GEAR: 43
};

const BLOCK_PROPS = {
    [BLOCKS.AIR]: { solid: false, color: 'transparent', hardness: 0 },
    [BLOCKS.DIRT]: { solid: true, color: '#795548', hardness: 1.0, itemType: ITEM_TYPES.BLOCK, name: 'Dirt' },
    [BLOCKS.GRASS]: { solid: true, color: '#4CAF50', hardness: 1.0, itemType: ITEM_TYPES.BLOCK, name: 'Grass Block' },
    [BLOCKS.STONE]: { solid: true, color: '#607D8B', hardness: 3.5, itemType: ITEM_TYPES.BLOCK, name: 'Stone', minPower: 2 },
    [BLOCKS.WOOD]: { solid: true, color: '#8D6E63', hardness: 2.0, itemType: ITEM_TYPES.BLOCK, name: 'Wood Log' },
    [BLOCKS.LEAVES]: { solid: false, color: '#2E7D32', hardness: 0.2, itemType: ITEM_TYPES.BLOCK, name: 'Leaves' },
    [BLOCKS.PLANK]: { solid: true, color: '#D7CCC8', hardness: 1.5, itemType: ITEM_TYPES.BLOCK, name: 'Wooden Plank' },
    [BLOCKS.COAL]: { solid: true, color: '#212121', hardness: 4.0, itemType: ITEM_TYPES.BLOCK, name: 'Coal Ore', minPower: 2 },
    [BLOCKS.IRON]: { solid: true, color: '#B0BEC5', hardness: 6.0, itemType: ITEM_TYPES.BLOCK, name: 'Iron Ore', minPower: 4 },
    [BLOCKS.GOLD]: { solid: true, color: '#FFD700', hardness: 8.0, itemType: ITEM_TYPES.BLOCK, name: 'Gold Ore', minPower: 7 },
    [BLOCKS.WOOD_PICKAXE]: { solid: false, color: '#A1887F', itemType: ITEM_TYPES.TOOL, power: 2.5, maxDurability: 30, name: 'Wooden Pickaxe' },
    [BLOCKS.STONE_PICKAXE]: { solid: false, color: '#78909C', itemType: ITEM_TYPES.TOOL, power: 5.0, maxDurability: 60, name: 'Stone Pickaxe' },
    [BLOCKS.SAND]: { solid: true, color: '#E0C080', hardness: 0.8, itemType: ITEM_TYPES.BLOCK, name: 'Sand' },
    [BLOCKS.WATER]: { solid: false, color: 'rgba(52,173,226,0.6)', hardness: 0, itemType: ITEM_TYPES.BLOCK, name: 'Water', unbreakable: true },
    [BLOCKS.TORCH]: { solid: false, color: '#f39c12', hardness: 0.2, itemType: ITEM_TYPES.BLOCK, name: 'Torch', light: true, lightRadius: 4.5 },
    [BLOCKS.IRON_INGOT]: { solid: false, color: '#d7d7d7', itemType: ITEM_TYPES.MATERIAL, name: 'Iron Ingot' },
    [BLOCKS.GOLD_INGOT]: { solid: false, color: '#ffd700', itemType: ITEM_TYPES.MATERIAL, name: 'Gold Ingot' },
    [BLOCKS.IRON_PICKAXE]: { solid: false, color: '#d7d7d7', itemType: ITEM_TYPES.TOOL, power: 8.0, maxDurability: 100, name: 'Iron Pickaxe' },
    [BLOCKS.GOLD_PICKAXE]: { solid: false, color: '#ffd700', itemType: ITEM_TYPES.TOOL, power: 12.0, maxDurability: 40, name: 'Gold Pickaxe' },
    [BLOCKS.CRAFTING_BENCH]: { solid: true, color: '#8a5a3c', hardness: 2.0, itemType: ITEM_TYPES.BLOCK, name: 'Crafting Bench' },
    [BLOCKS.FURNACE]: { solid: true, color: '#5b5b5b', hardness: 3.0, itemType: ITEM_TYPES.BLOCK, name: 'Furnace', light: true, lightRadius: 4.0 },
    [BLOCKS.DEEP_STONE]: { solid: true, color: '#37474F', hardness: 5.0, itemType: ITEM_TYPES.BLOCK, name: 'Deepstone', minPower: 4 },
    [BLOCKS.IRON_HELMET]: { solid: false, color: '#cfd8dc', itemType: ITEM_TYPES.ARMOR, slot: 'head', defense: 4, name: 'Iron Helmet' },
    [BLOCKS.IRON_CHEST]: { solid: false, color: '#cfd8dc', itemType: ITEM_TYPES.ARMOR, slot: 'chest', defense: 6, name: 'Iron Chestplate' },
    [BLOCKS.IRON_LEGS]: { solid: false, color: '#cfd8dc', itemType: ITEM_TYPES.ARMOR, slot: 'legs', defense: 5, name: 'Iron Leggings' },
    [BLOCKS.IRON_BOOTS]: { solid: false, color: '#cfd8dc', itemType: ITEM_TYPES.ARMOR, slot: 'feet', defense: 3, name: 'Iron Boots' },
    [BLOCKS.GOLD_HELMET]: { solid: false, color: '#ffd700', itemType: ITEM_TYPES.ARMOR, slot: 'head', defense: 5, name: 'Gold Helmet' },
    [BLOCKS.GOLD_CHEST]: { solid: false, color: '#ffd700', itemType: ITEM_TYPES.ARMOR, slot: 'chest', defense: 8, name: 'Gold Chestplate' },
    [BLOCKS.GOLD_LEGS]: { solid: false, color: '#ffd700', itemType: ITEM_TYPES.ARMOR, slot: 'legs', defense: 7, name: 'Gold Leggings' },
    [BLOCKS.GOLD_BOOTS]: { solid: false, color: '#ffd700', itemType: ITEM_TYPES.ARMOR, slot: 'feet', defense: 4, name: 'Gold Boots' },
    [BLOCKS.IRON_SWORD]: { solid: false, color: '#e74c3c', itemType: ITEM_TYPES.TOOL, power: 7.0, maxDurability: 80, name: 'Iron Sword' },
    [BLOCKS.CHEST]: { solid: true, color: '#c68642', hardness: 2.5, itemType: ITEM_TYPES.BLOCK, name: 'Chest' },
    // New
    [BLOCKS.COPPER]: { solid: true, color: '#b87333', hardness: 4.5, itemType: ITEM_TYPES.BLOCK, name: 'Copper Ore', minPower: 2 },
    [BLOCKS.COPPER_INGOT]: { solid: false, color: '#da8a67', itemType: ITEM_TYPES.MATERIAL, name: 'Copper Ingot' },
    [BLOCKS.COPPER_PICKAXE]: { solid: false, color: '#da8a67', itemType: ITEM_TYPES.TOOL, power: 6.5, maxDurability: 80, name: 'Copper Pickaxe' },
    [BLOCKS.CRYSTAL]: { solid: true, color: '#9b59b6', hardness: 9.0, itemType: ITEM_TYPES.BLOCK, name: 'Crystal Ore', minPower: 7, light: true, lightRadius: 2.5 },
    [BLOCKS.CRYSTAL_SHARD]: { solid: false, color: '#bb8fce', itemType: ITEM_TYPES.MATERIAL, name: 'Crystal Shard' },
    [BLOCKS.SNOW]: { solid: true, color: '#eceff1', hardness: 0.6, itemType: ITEM_TYPES.BLOCK, name: 'Snow' },
    [BLOCKS.ICE]: { solid: true, color: '#81d4fa', hardness: 1.2, itemType: ITEM_TYPES.BLOCK, name: 'Ice' },
    [BLOCKS.SNOW_GRASS]: { solid: true, color: '#cfd8dc', hardness: 1.0, itemType: ITEM_TYPES.BLOCK, name: 'Snowy Grass' },
    [BLOCKS.MOSSY_STONE]: { solid: true, color: '#556b2f', hardness: 3.2, itemType: ITEM_TYPES.BLOCK, name: 'Mossy Stone', minPower: 2 },
    [BLOCKS.MUSHROOM]: { solid: false, color: '#e74c3c', hardness: 0.1, itemType: ITEM_TYPES.BLOCK, name: 'Cave Mushroom', light: true, lightRadius: 1.5 },
    [BLOCKS.CAVE_BRICK]: { solid: true, color: '#6d4c41', hardness: 4.0, itemType: ITEM_TYPES.BLOCK, name: 'Cave Brick', minPower: 2 },
    [BLOCKS.GEAR]: { solid: false, color: '#95a5a6', itemType: ITEM_TYPES.MATERIAL, name: 'Ancient Gear' }
};

const RECIPES = [
    { name: 'Wood Planks', station: null, result: { type: BLOCKS.PLANK, count: 4 },
      grid: [null, null, null, null, BLOCKS.WOOD, null, null, null, null] },
    { name: 'Crafting Bench', station: null, result: { type: BLOCKS.CRAFTING_BENCH, count: 1 },
      grid: [BLOCKS.PLANK, BLOCKS.PLANK, null, BLOCKS.PLANK, BLOCKS.PLANK, null, null, null, null] },
    { name: 'Torch', station: null, result: { type: BLOCKS.TORCH, count: 4 },
      grid: [null, null, null, null, BLOCKS.COAL, null, null, BLOCKS.WOOD, null] },
    { name: 'Chest', station: null, result: { type: BLOCKS.CHEST, count: 1 },
      grid: [BLOCKS.PLANK, BLOCKS.PLANK, BLOCKS.PLANK, BLOCKS.PLANK, null, BLOCKS.PLANK, BLOCKS.PLANK, BLOCKS.PLANK, BLOCKS.PLANK] },
    { name: 'Wooden Pickaxe', station: 'bench', result: { type: BLOCKS.WOOD_PICKAXE, count: 1, durability: 30 },
      grid: [BLOCKS.PLANK, BLOCKS.PLANK, BLOCKS.PLANK, null, BLOCKS.WOOD, null, null, BLOCKS.WOOD, null] },
    { name: 'Furnace', station: 'bench', result: { type: BLOCKS.FURNACE, count: 1 },
      grid: [BLOCKS.STONE, BLOCKS.STONE, BLOCKS.STONE, BLOCKS.STONE, null, BLOCKS.STONE, BLOCKS.STONE, BLOCKS.STONE, BLOCKS.STONE] },
    { name: 'Stone Pickaxe', station: 'bench', result: { type: BLOCKS.STONE_PICKAXE, count: 1, durability: 60 },
      grid: [BLOCKS.STONE, BLOCKS.STONE, BLOCKS.STONE, null, BLOCKS.WOOD, null, null, BLOCKS.WOOD, null] },
    { name: 'Smelt Iron Ingot', station: 'furnace', result: { type: BLOCKS.IRON_INGOT, count: 1 },
      grid: [null, null, null, null, BLOCKS.IRON, null, null, BLOCKS.COAL, null] },
    { name: 'Smelt Gold Ingot', station: 'furnace', result: { type: BLOCKS.GOLD_INGOT, count: 1 },
      grid: [null, null, null, null, BLOCKS.GOLD, null, null, BLOCKS.COAL, null] },
    { name: 'Smelt Copper Ingot', station: 'furnace', result: { type: BLOCKS.COPPER_INGOT, count: 1 },
      grid: [null, null, null, null, BLOCKS.COPPER, null, null, BLOCKS.COAL, null] },
    { name: 'Copper Pickaxe', station: 'bench', result: { type: BLOCKS.COPPER_PICKAXE, count: 1, durability: 80 },
      grid: [BLOCKS.COPPER_INGOT, BLOCKS.COPPER_INGOT, BLOCKS.COPPER_INGOT, null, BLOCKS.WOOD, null, null, BLOCKS.WOOD, null] },
    { name: 'Iron Pickaxe', station: 'bench', result: { type: BLOCKS.IRON_PICKAXE, count: 1, durability: 100 },
      grid: [BLOCKS.IRON_INGOT, BLOCKS.IRON_INGOT, BLOCKS.IRON_INGOT, null, BLOCKS.WOOD, null, null, BLOCKS.WOOD, null] },
    { name: 'Gold Pickaxe', station: 'bench', result: { type: BLOCKS.GOLD_PICKAXE, count: 1, durability: 40 },
      grid: [BLOCKS.GOLD_INGOT, BLOCKS.GOLD_INGOT, BLOCKS.GOLD_INGOT, null, BLOCKS.WOOD, null, null, BLOCKS.WOOD, null] },
    { name: 'Iron Helmet', station: 'bench', result: { type: BLOCKS.IRON_HELMET, count: 1 },
      grid: [BLOCKS.IRON_INGOT, BLOCKS.IRON_INGOT, BLOCKS.IRON_INGOT, BLOCKS.IRON_INGOT, null, BLOCKS.IRON_INGOT, null, null, null] },
    { name: 'Iron Chestplate', station: 'bench', result: { type: BLOCKS.IRON_CHEST, count: 1 },
      grid: [BLOCKS.IRON_INGOT, null, BLOCKS.IRON_INGOT, BLOCKS.IRON_INGOT, BLOCKS.IRON_INGOT, BLOCKS.IRON_INGOT, BLOCKS.IRON_INGOT, BLOCKS.IRON_INGOT, BLOCKS.IRON_INGOT] },
    { name: 'Iron Leggings', station: 'bench', result: { type: BLOCKS.IRON_LEGS, count: 1 },
      grid: [BLOCKS.IRON_INGOT, BLOCKS.IRON_INGOT, BLOCKS.IRON_INGOT, BLOCKS.IRON_INGOT, null, BLOCKS.IRON_INGOT, BLOCKS.IRON_INGOT, null, BLOCKS.IRON_INGOT] },
    { name: 'Iron Boots', station: 'bench', result: { type: BLOCKS.IRON_BOOTS, count: 1 },
      grid: [null, null, null, BLOCKS.IRON_INGOT, null, BLOCKS.IRON_INGOT, BLOCKS.IRON_INGOT, null, BLOCKS.IRON_INGOT] },
    { name: 'Gold Helmet', station: 'bench', result: { type: BLOCKS.GOLD_HELMET, count: 1 },
      grid: [BLOCKS.GOLD_INGOT, BLOCKS.GOLD_INGOT, BLOCKS.GOLD_INGOT, BLOCKS.GOLD_INGOT, null, BLOCKS.GOLD_INGOT, null, null, null] },
    { name: 'Gold Chestplate', station: 'bench', result: { type: BLOCKS.GOLD_CHEST, count: 1 },
      grid: [BLOCKS.GOLD_INGOT, null, BLOCKS.GOLD_INGOT, BLOCKS.GOLD_INGOT, BLOCKS.GOLD_INGOT, BLOCKS.GOLD_INGOT, BLOCKS.GOLD_INGOT, BLOCKS.GOLD_INGOT, BLOCKS.GOLD_INGOT] },
    { name: 'Gold Leggings', station: 'bench', result: { type: BLOCKS.GOLD_LEGS, count: 1 },
      grid: [BLOCKS.GOLD_INGOT, BLOCKS.GOLD_INGOT, BLOCKS.GOLD_INGOT, BLOCKS.GOLD_INGOT, null, BLOCKS.GOLD_INGOT, BLOCKS.GOLD_INGOT, null, BLOCKS.GOLD_INGOT] },
    { name: 'Gold Boots', station: 'bench', result: { type: BLOCKS.GOLD_BOOTS, count: 1 },
      grid: [null, null, null, BLOCKS.GOLD_INGOT, null, BLOCKS.GOLD_INGOT, BLOCKS.GOLD_INGOT, null, BLOCKS.GOLD_INGOT] },
    { name: 'Iron Sword', station: 'bench', result: { type: BLOCKS.IRON_SWORD, count: 1, durability: 80 },
      grid: [null, BLOCKS.IRON_INGOT, null, null, BLOCKS.IRON_INGOT, null, null, BLOCKS.WOOD, null] }
];

const firebaseConfig = {
    apiKey: 'AIzaSyAcQFP_pklDrcs57iXbQAt3DZtdZyXLAKs',
    authDomain: 'd-engine-sandbox.firebaseapp.com',
    projectId: 'd-engine-sandbox',
    storageBucket: 'd-engine-sandbox.firebasestorage.app',
    messagingSenderId: '859105469634',
    appId: '1:859105469634:web:e7549aaf7e5ea9d718c75b'
};

const ICE_CONFIG = {
    iceServers: [
        { urls: 'stun:stun.l.google.com:19302' },
        { urls: 'stun:stun1.l.google.com:19302' },
        { urls: 'stun:stun2.l.google.com:19302' },
        { urls: 'turn:openrelay.metered.ca:80', username: 'openrelayproject', credential: 'openrelayproject' },
        { urls: 'turn:openrelay.metered.ca:443', username: 'openrelayproject', credential: 'openrelayproject' }
    ]
};

function defaultSettingsObj() {
    return { shake: true, lighting: true, dust: true, vignette: true, smoothCamera: true };
}
function defaultSkinObj() {
    return { skin: '#ffcc99', shirt: '#e74c3c', pants: '#2c3e50', hair: '#3b2412' };
}
function emptySlot() {
    return { type: BLOCKS.AIR, count: 0, durability: 0 };
}
function emptyChestSlots() {
    return new Array(CHEST_SIZE).fill(null).map(emptySlot);
}
function chestKey(col, row) {
    return col + ',' + row;
}
function clamp(v, min, max) {
    return Math.max(min, Math.min(max, v));
}
function hashString(str) {
    let hash = 0;
    for (let i = 0; i < str.length; i++) {
        hash = (hash << 5) - hash + str.charCodeAt(i);
        hash |= 0;
    }
    return Math.abs(hash);
}
function pseudoNoise(x, y, seed) {
    let n = Math.sin(x * 12.9898 + y * 78.233 + seed) * 43758.5453;
    return n - Math.floor(n);
}
