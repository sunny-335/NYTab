import { http } from './request'

/**
 * Weather API 客户端。
 *
 * 对应后端 Task 8 完成的四个端点：
 *  - GET    /weather              获取天气（city 或 lat/lng）
 *  - GET    /weather/cities       城市搜索
 *  - GET    /weather/settings     读取设置（密钥脱敏）
 *  - PUT    /weather/settings     更新设置
 *
 * 响应已在 request.ts 的拦截器中解包 ApiEnvelope，这里直接拿到 data。
 */

/** 天气数据源。 */
export type WeatherProvider = 'gaode' | 'hefeng'

/** GET /weather 返回的天气数据。 */
export interface WeatherData {
  /** 温度（摄氏度，字符串形式，便于后端原样回传） */
  temp: string
  /** 天气状况（如「晴」「多云」） */
  condition: string
  /** 湿度 */
  humidity: string
  /** 风向风力 */
  wind: string
  /** 城市名 */
  city: string
}

/** GET /weather/cities 返回的单条城市项。 */
export interface CityOption {
  adcode: string
  name: string
  province: string
}

/**
 * GET /weather/settings 返回的设置。
 * 密钥字段后端已脱敏（如 `gaode_key = "abcd****wxyz"`）。
 */
export interface WeatherSettings {
  provider: WeatherProvider
  gaode_key: string
  hefeng_key: string
  default_city: string
  auto_location: boolean
}

export const weatherApi = {
  /** 按城市名或经纬度获取当前天气。 */
  getWeather(
    params: { city?: string; lat?: number; lng?: number },
  ): Promise<WeatherData> {
    return http.get<WeatherData>('/weather', { params })
  },

  /** 城市搜索（高德 adcode 反查）。 */
  searchCities(keyword: string): Promise<CityOption[]> {
    return http.get<CityOption[]>('/weather/cities', {
      params: { keyword },
    })
  },

  /** 读取天气设置（密钥脱敏）。 */
  getSettings(): Promise<WeatherSettings> {
    return http.get<WeatherSettings>('/weather/settings')
  },

  /** 更新天气设置。 */
  updateSettings(payload: Partial<WeatherSettings>): Promise<WeatherSettings> {
    return http.put<WeatherSettings>('/weather/settings', payload)
  },
}
