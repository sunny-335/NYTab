import { http } from './request'

/** Database connection form payload.
 *  Field names match the actual backend (SetupController expects `name`, not `dbname`). */
export interface DbConfig {
  host: string
  port: number | string
  name: string
  user: string
  password: string
}

/** Admin account form payload. */
export interface AdminConfig {
  username: string
  password: string
  email?: string
}

/** A single environment-requirement check returned by /setup/status. */
export interface RequirementItem {
  ok: boolean
  required?: string
  actual?: string
  path?: string
}

export type Requirements = Record<string, RequirementItem>

/** GET /setup/status response data. */
export interface SetupStatus {
  installed: boolean
  requirements?: Requirements
  version?: string
}

/** POST /setup/test-database response data.
 *  `databaseExists=false` indicates the target DB is missing and will be
 *  auto-created during install; `canCreate` reflects the connecting role's
 *  CREATEDB privilege (only meaningful when databaseExists=false). */
export interface TestDbResult {
  ok: boolean
  databaseExists: boolean
  canCreate: boolean
  server_version: string
}

/** POST /setup/install response data. */
export interface InstallResult {
  version: string
}

/** POST /setup/install request body.
 *  The backend expects `database` (not `db`); no `jwt` field is consumed. */
export interface InstallPayload {
  database: DbConfig
  admin: AdminConfig
  /** 可选：写入后端 .env 的 CORS_ORIGINS，默认由前端填 window.location.origin。 */
  corsOrigins?: string
}

export const setupApi = {
  status: () => http.get<SetupStatus>('/setup/status'),

  testDatabase: (db: DbConfig) =>
    http.post<TestDbResult>('/setup/test-database', db),

  install: (payload: InstallPayload) =>
    http.post<InstallResult>('/setup/install', payload),
}
