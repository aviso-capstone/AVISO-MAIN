import predictionFile from '@/data/aviso_prediction.json';
import roadCellsFile from '@/data/aviso_risk_road_cells.json';

/**
 * "Risk areas" layer on the Live Map: the team's risk map (same file as the
 * rider app's aviso-mobile/assets/data/aviso_prediction.json) scores 25 m
 * squares; the top flag_top_pct (10%) are the high-risk squares that trigger
 * "Possible hazard ahead" on the rider's phone. Keep both copies of the file
 * the same when the risk map is updated.
 */

type RiskCell = { lat: number; lon: number; risk: number };
type PredictionModel = { version?: string; flag_top_pct?: number; cell_m?: number; cells?: unknown[] };

export type RiskSquares = GeoJSON.FeatureCollection<GeoJSON.Polygon>;

const METERS_PER_DEGREE_LAT = 111_320;

function isCell(c: unknown): c is RiskCell {
    if (typeof c !== 'object' || c === null) return false;
    const { lat, lon, risk } = c as Record<string, unknown>;
    return [lat, lon, risk].every((v) => typeof v === 'number' && Number.isFinite(v));
}

type RoadCellsFile = { model_version?: string; cells?: unknown[] };

const cellKey = (lat: number, lon: number) => `${lat.toFixed(6)},${lon.toFixed(6)}`;

/**
 * The squares on the road (data/aviso_risk_road_cells.json: squares a recorded
 * ride path or a saved hazard passes through), or null to keep every square when
 * the list was built for another risk-map version.
 */
function roadCellKeys(roadFile: RoadCellsFile, modelVersion: string | undefined): Set<string> | null {
    if (!Array.isArray(roadFile.cells) || roadFile.model_version !== modelVersion) return null;
    return new Set(
        roadFile.cells
            .filter((c): c is [number, number] => Array.isArray(c) && typeof c[0] === 'number' && typeof c[1] === 'number')
            .map(([lat, lon]) => cellKey(lat, lon)),
    );
}

/**
 * The high-risk squares on the road as polygons, same rule as the rider app (top
 * flag_top_pct of the scores). The risk map's spread into buildings and water is
 * not drawn: no rider can be there and those squares never warn.
 */
export function riskSquares(model: PredictionModel, roadFile: RoadCellsFile = {}): RiskSquares {
    const cells = Array.isArray(model.cells) ? model.cells.filter(isCell) : [];
    if (cells.length === 0) return { type: 'FeatureCollection', features: [] };

    const pct = typeof model.flag_top_pct === 'number' && model.flag_top_pct > 0 ? model.flag_top_pct : 10;
    const half = (typeof model.cell_m === 'number' && model.cell_m > 0 ? model.cell_m : 25) / 2;
    const sorted = [...cells].sort((a, b) => b.risk - a.risk);
    const count = Math.max(1, Math.floor((sorted.length * pct) / 100));
    const cutoff = sorted[count - 1].risk;
    const road = roadCellKeys(roadFile, model.version);

    return {
        type: 'FeatureCollection',
        features: cells
            .filter((c) => c.risk >= cutoff && (road === null || road.has(cellKey(c.lat, c.lon))))
            .map((c) => {
                const dLat = half / METERS_PER_DEGREE_LAT;
                const dLon = half / (METERS_PER_DEGREE_LAT * Math.cos((c.lat * Math.PI) / 180));
                const [s, n, w, e] = [c.lat - dLat, c.lat + dLat, c.lon - dLon, c.lon + dLon];
                return {
                    type: 'Feature',
                    properties: {},
                    geometry: { type: 'Polygon', coordinates: [[[w, s], [e, s], [e, n], [w, n], [w, s]]] },
                };
            }),
    };
}

let cached: RiskSquares | null = null;

/** The bundled risk map's high-risk squares on the road, built once. */
export function bundledRiskSquares(): RiskSquares {
    if (cached === null) cached = riskSquares(predictionFile as PredictionModel, roadCellsFile as RoadCellsFile);
    return cached;
}

/** The bundled risk map's date, e.g. "2026-11-19". */
export const RISK_MAP_VERSION: string | null =
    typeof (predictionFile as PredictionModel).version === 'string' ? (predictionFile as PredictionModel).version! : null;
