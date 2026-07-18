/**
 * 搜索引擎内置清单与查询辅助。
 *
 * 6 个内置引擎：Bing / Google / Baidu / DuckDuckGo / 搜狗 / 360。
 * `icon` 字段使用文字/emoji 占位（后续可替换为 SVG），
 * `searchUrl` 中以 `{query}` 作为关键字占位符。
 */
export interface SearchEngine {
  id: string
  name: string
  icon: string
  searchUrl: string
}

export const SEARCH_ENGINES: SearchEngine[] = [
  { id: 'bing', name: 'Bing', icon: '🔍', searchUrl: 'https://www.bing.com/search?q={query}' },
  { id: 'google', name: 'Google', icon: 'G', searchUrl: 'https://www.google.com/search?q={query}' },
  { id: 'baidu', name: '百度', icon: 'B', searchUrl: 'https://www.baidu.com/s?wd={query}' },
  { id: 'duckduckgo', name: 'DuckDuckGo', icon: 'D', searchUrl: 'https://duckduckgo.com/?q={query}' },
  { id: 'sogou', name: '搜狗', icon: 'S', searchUrl: 'https://www.sogou.com/web?query={query}' },
  { id: '360', name: '360', icon: '3', searchUrl: 'https://www.so.com/s?q={query}' },
]

/** 默认引擎(Bing)。 */
export const DEFAULT_ENGINE = SEARCH_ENGINES[0]

/**
 * 把关键字填入引擎 searchUrl,得到最终跳转 URL。
 * 同时对 query 做 encodeURIComponent 以避免空格/特殊字符破坏 URL。
 */
export function buildSearchUrl(engine: SearchEngine, query: string): string {
  return engine.searchUrl.replace('{query}', encodeURIComponent(query))
}

/**
 * 引擎查询 composable。
 *
 * 内置引擎清单是常量,这里以 composable 形式暴露主要是为了与项目其它
 * `useXxx` 风格保持一致,并便于后续扩展(例如从后端拉取引擎清单)。
 */
export function useSearchEngines() {
  const engines = SEARCH_ENGINES

  /** 按 id 查询引擎,未命中时回退到默认引擎(Bing)。 */
  const getEngine = (id: string): SearchEngine =>
    engines.find((e) => e.id === id) ?? DEFAULT_ENGINE

  return { engines, getEngine, defaultEngine: DEFAULT_ENGINE }
}
