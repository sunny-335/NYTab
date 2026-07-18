import { http } from './request'

/**
 * 书签同步模块 API 封装。
 *
 * 路由与字段严格对齐后端实现：
 *   - 路由前缀为 `/bookmarks` 与 `/bookmark-categories`（注意分类是独立路由）；
 *   - 字段为 snake_case：`icon_url`、`category_id`、`sort_order`、`parent_id`；
 *   - 书签的 `tags` 等扩展属性存放在 JSONB `extra` 字段中
 *     （`{ tags, color, note, open_in_new_tab }`），不是顶层字段；
 *   - 分类接口返回嵌套树（每个节点含 `children` 数组），不是扁平列表；
 *   - 响应已被 axios 拦截器解包：`http.get('/bookmarks')` 直接返回 `Bookmark[]`。
 *
 * 注意 reorder 走 `PUT /bookmarks/reorder`，由于后端路由顺序问题被
 * `/bookmarks/{id}` 捕获（id="reorder"），controller 内部转发处理。
 */
export interface BookmarkExtra {
  tags?: string[]
  color?: string | null
  note?: string
  open_in_new_tab?: boolean
}

export interface Bookmark {
  id: number
  user_id: number
  category_id: number | null
  title: string
  url: string
  description?: string | null
  icon_url?: string | null
  sort_order: number
  extra: BookmarkExtra
  created_at?: string | null
  updated_at?: string | null
}

export interface BookmarkCategory {
  id: number
  user_id: number
  parent_id: number | null
  name: string
  icon?: string | null
  sort_order: number
  extra?: Record<string, unknown>
  children?: BookmarkCategory[]
  created_at?: string | null
  updated_at?: string | null
}

/** 创建/更新书签时使用的 payload（与后端 Repository::create / update 对齐）。 */
export interface BookmarkWritePayload {
  title?: string
  url?: string
  description?: string | null
  category_id?: number | null
  icon_url?: string | null
  sort_order?: number
  extra?: Partial<BookmarkExtra>
}

/** 创建/更新分类时使用的 payload。 */
export interface CategoryWritePayload {
  name?: string
  parent_id?: number | null
  icon?: string | null
  sort_order?: number
}

/** `POST /bookmarks/{id}/icon` 返回结构。 */
export interface BookmarkIconUploadResponse {
  icon_url: string
}

export const bookmarkApi = {
  /* --------------------------- Bookmarks --------------------------- */

  /** 列表，可选按分类/关键字过滤。 */
  listBookmarks: (params?: { categoryId?: number; keyword?: string }) =>
    http.get<Bookmark[]>('/bookmarks', {
      params: {
        ...(params?.categoryId != null
          ? { category_id: params.categoryId }
          : {}),
        ...(params?.keyword ? { keyword: params.keyword } : {}),
      },
    }),

  /** 详情。 */
  getBookmark: (id: number) => http.get<Bookmark>(`/bookmarks/${id}`),

  /** 新增。 */
  createBookmark: (data: BookmarkWritePayload) =>
    http.post<Bookmark>('/bookmarks', data),

  /** 更新。 */
  updateBookmark: (id: number, data: BookmarkWritePayload) =>
    http.put<Bookmark>(`/bookmarks/${id}`, data),

  /** 删除。 */
  deleteBookmark: (id: number) => http.delete<null>(`/bookmarks/${id}`),

  /** 拖拽批量排序（PUT，不是 POST）。 */
  reorderBookmarks: (items: Array<{ id: number; sort_order: number }>) =>
    http.put<null>('/bookmarks/reorder', { items }),

  /** 上传自定义图标（multipart）。后端返回 `{ icon_url }`。 */
  uploadIcon: (id: number, file: File) => {
    const formData = new FormData()
    formData.append('icon', file)
    return http.post<BookmarkIconUploadResponse>(
      `/bookmarks/${id}/icon`,
      formData,
      { headers: { 'Content-Type': 'multipart/form-data' } },
    )
  },

  /**
   * 触发后端再次抓取目标站点 favicon（同步返回新图标 URL）。
   */
  fetchIcon: (id: number) =>
    http.post<BookmarkIconUploadResponse>(`/bookmarks/${id}/fetch-icon`),

  /* --------------------------- Categories --------------------------- */

  /** 获取分类树（嵌套结构，含 children）。 */
  listCategories: () => http.get<BookmarkCategory[]>('/bookmark-categories'),

  /** 新增分类。 */
  createCategory: (data: CategoryWritePayload) =>
    http.post<BookmarkCategory>('/bookmark-categories', data),

  /** 更新分类。 */
  updateCategory: (id: number, data: CategoryWritePayload) =>
    http.put<BookmarkCategory>(`/bookmark-categories/${id}`, data),

  /** 删除分类（子分类被 ON DELETE CASCADE 删除，书签 category_id 被置空）。 */
  deleteCategory: (id: number) =>
    http.delete<null>(`/bookmark-categories/${id}`),

  /** 拖拽批量排序分类（PUT，不是 POST）。 */
  reorderCategories: (items: Array<{ id: number; sort_order: number }>) =>
    http.put<null>('/bookmark-categories/reorder', { items }),
}
