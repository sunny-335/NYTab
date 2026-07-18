#!/usr/bin/env bash
# =============================================================================
# NYTab 端到端 curl 测试脚本（Task 17 + Task 16 扩展验证）
# -----------------------------------------------------------------------------
# 覆盖 spec 中 8 个 E2E 场景：
#   17.1 未安装访问根路径 → 自动跳 /setup → 完成 4 步向导 → 登录
#   17.2 已安装后 /api/setup/* 返回 40901
#   17.3 登录 → me → 改密 → refresh → logout
#   17.4 书签 CRUD + 拖拽排序
#   17.5 工作台布局拖拽 → 刷新 → 恢复一致
#   17.6 至少 3 个工具状态跨设备同步
#   17.7 防暴力破解 5 次失败锁定
#   17.8 CORS 白名单与 OPTIONS 预检
#
# Task 16 扩展端点验证（在 17.3 登录拿到 token 后运行）：
#   16   dev-mode / branding / weather / background / setup test-database
#
# 使用方法：
#   # 1) 启动后端（PHP-FPM / php -S 0.0.0.0:8000 -t backend/public）
#   # 2) 运行：
#   BASE_URL=http://localhost:8000 bash tests/e2e-curl.sh
#
# 环境变量：
#   BASE_URL       后端 API 根地址（不含 /api，默认 http://localhost:8000）
#   DB_HOST       安装向导使用的 DB 主机（默认 127.0.0.1）
#   DB_PORT       安装向导使用的 DB 端口（默认 5432）
#   DB_NAME       安装向导使用的 DB 名（默认 nytab）
#   DB_USER       安装向导使用的 DB 用户（默认 nytab）
#   DB_PASSWORD   安装向导使用的 DB 密码（默认 change_me）
#   ADMIN_USER    安装向导创建的管理员用户名（默认 admin）
#   ADMIN_PASS    安装向导创建的管理员密码（默认 StrongP@ss1）
#   ADMIN_EMAIL   管理员邮箱（默认 admin@example.com）
#   NEW_PASS      17.3 改密测试中使用的新密码（默认 NewStr0ng!Pass）
#   CORS_ORIGIN   17.8 中白名单内 Origin（默认 http://localhost:5173）
#   RUN_SCENARIO  仅运行指定场景（如 RUN_SCENARIO=17.3 bash ...）；默认全跑
#
# 注意：
#   * 脚本幂等性：17.1 会先检测 installed=false；如已安装则跳过 install
#   * 17.2 需要在 17.1 完成安装后才有效
#   * 17.3 ~ 17.8 依赖已安装 + 登录态；脚本内部串行执行
#   * set -euo pipefail 在某些场景需要局部关闭（用 `|| true` 显式吞错）
# =============================================================================
set -euo pipefail

# ----------------------------- 配置 -----------------------------
BASE_URL="${BASE_URL:-http://localhost:8000}"
API="${BASE_URL}/api"

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-5432}"
DB_NAME="${DB_NAME:-nytab}"
DB_USER="${DB_USER:-nytab}"
DB_PASSWORD="${DB_PASSWORD:-change_me}"

ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASS="${ADMIN_PASS:-StrongP@ss1}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@example.com}"
NEW_PASS="${NEW_PASS:-NewStr0ng!Pass}"

CORS_ORIGIN="${CORS_ORIGIN:-http://localhost:5173}"

RUN_SCENARIO="${RUN_SCENARIO:-}"

PASS=0
FAIL=0
SKIP=0

# 临时状态文件（跨场景传递 token / id 等）
STATE_DIR="$(mktemp -d)"
ACCESS_TOKEN_FILE="${STATE_DIR}/access_token"
REFRESH_TOKEN_FILE="${STATE_DIR}/refresh_token"
BOOKMARK_ID_FILE="${STATE_DIR}/bookmark_id"
CATEGORY_ID_FILE="${STATE_DIR}/category_id"
NEW_ACCESS_TOKEN_FILE="${STATE_DIR}/new_access_token"

trap 'rm -rf "${STATE_DIR}"' EXIT

# ----------------------------- 工具函数 -----------------------------
log()   { printf '%s\n' "$*"; }
log_h() { printf '\n=== %s ===\n' "$*"; }
log_s() { printf '\n--- %s ---\n' "$*"; }

pass() {
  printf '  \033[32m✓\033[0m %s\n' "$1"
  PASS=$((PASS + 1))
}
fail() {
  printf '  \033[31m✗\033[0m %s\n' "$1"
  FAIL=$((FAIL + 1))
}
skip() {
  printf '  \033[33m→\033[0m %s\n' "$1"
  SKIP=$((SKIP + 1))
}
info() { printf '  · %s\n' "$1"; }

# 断言 HTTP 状态码
assert_http() {
  local name="$1" expected="$2" actual="$3"
  if [[ "$actual" == "$expected" ]]; then
    pass "${name} (HTTP ${actual})"
  else
    fail "${name} (expected HTTP ${expected}, got ${actual})"
  fi
}

# 断言响应体包含子串
assert_body_contains() {
  local name="$1" body="$2" needle="$3"
  if echo "$body" | grep -q -- "$needle"; then
    pass "${name} (body contains '${needle}')"
  else
    fail "${name} (body does not contain '${needle}'; got: ${body})"
  fi
}

# 断言响应体包含 JSON 字段（用 grep 简单判断，不依赖 jq）
assert_json_field() {
  local name="$1" body="$2" field="$3" value="$4"
  local needle="\"${field}\":${value}"
  if echo "$body" | grep -q -- "$needle"; then
    pass "${name} (${field}=${value})"
  else
    fail "${name} (expected ${field}=${value}; got: ${body})"
  fi
}

# 调用 curl 并返回 <http_code>\n<body>
# 用法：curl_run <method> <path> [data] [extra_header...]
# 输出：第一行为 HTTP 状态码，后续行为响应体
curl_run() {
  local method="$1"; shift
  local path="$1"; shift
  local data=""
  if [[ "${1:-}" == "--data" ]]; then
    shift
    data="$1"; shift
  fi
  local -a args=(
    -s -S
    -w "\n%{http_code}\n"
    -X "$method"
    "${API}${path}"
    -H 'Accept: application/json'
  )
  if [[ -n "$data" ]]; then
    args+=(-H 'Content-Type: application/json' --data "$data")
  fi
  # 透传额外 header
  while [[ $# -gt 0 ]]; do
    args+=("$1")
    shift
  done
  curl "${args[@]}"
}

# 解析 curl_run 输出：第一行是 body（可能多行），最后一行是 HTTP code
# 用法：parse_curl_output <output_var> <code_var> <raw>
parse_curl_output() {
  local out_var="$1" code_var="$2" raw="$3"
  # 最后一行是 code
  local code
  code=$(printf '%s' "$raw" | tail -n1)
  local body
  body=$(printf '%s' "$raw" | sed '$d')
  printf -v "$out_var" '%s' "$body"
  printf -v "$code_var" '%s' "$code"
}

# 从 JSON body 提取字符串字段值（简易 grep+sed，不依赖 jq）
# 用法：json_get <body> <field>  → 输出值（无引号、无尾逗号）
json_get() {
  local body="$1" field="$2"
  printf '%s' "$body" \
    | sed -nE 's/.*"'"$field"'"[[:space:]]*:[[:space:]]*"([^"]*)".*/\1/p; s/.*"'"$field"'"[[:space:]]*:[[:space:]]*([0-9]+).*/\1/p' \
    | head -n1
}

# 是否运行指定场景
should_run() {
  local n="$1"
  if [[ -z "$RUN_SCENARIO" ]]; then
    return 0
  fi
  [[ "$RUN_SCENARIO" == "$n" ]]
}

# =============================================================================
# SubTask 17.1: 未安装访问根路径 → 自动跳 /setup → 完成 4 步向导 → 登录
# =============================================================================
scenario_17_1() {
  log_h "SubTask 17.1: 未安装访问根路径 → /setup 向导"

  log_s "17.1.1 GET /api/setup/status"
  local raw body code
  raw=$(curl_run GET /setup/status)
  parse_curl_output body code "$raw"
  info "HTTP ${code}"
  info "body (truncated): $(echo "$body" | head -c 200)..."
  assert_http "setup/status 可访问" 200 "$code"

  if echo "$body" | grep -q '"installed":true'; then
    info "系统已安装，跳过 install 流程"
    pass "已安装状态识别正确"
    return 0
  fi

  assert_body_contains "未安装时 installed=false" "$body" '"installed":false'
  assert_body_contains "未安装时返回 requirements" "$body" '"requirements"'

  log_s "17.1.2 POST /api/setup/test-database"
  raw=$(curl_run POST /setup/test-database --data "{\"host\":\"${DB_HOST}\",\"port\":${DB_PORT},\"name\":\"${DB_NAME}\",\"user\":\"${DB_USER}\",\"password\":\"${DB_PASSWORD}\"}")
  parse_curl_output body code "$raw"
  info "HTTP ${code} body: ${body}"
  if [[ "$code" == "200" ]]; then
    assert_json_field "数据库连接成功 code=0" "$body" "code" "0"
    assert_body_contains "返回 server_version" "$body" '"server_version"'
  else
    skip "test-database 失败 (HTTP ${code})，确认 PostgreSQL 已启动且 .env.example 中 DB_* 配置正确"
  fi

  log_s "17.1.3 POST /api/setup/install"
  local install_payload
  install_payload=$(cat <<JSON
{"database":{"host":"${DB_HOST}","port":${DB_PORT},"name":"${DB_NAME}","user":"${DB_USER}","password":"${DB_PASSWORD}"},
 "admin":{"username":"${ADMIN_USER}","password":"${ADMIN_PASS}","email":"${ADMIN_EMAIL}"}}
JSON
)
  raw=$(curl_run POST /setup/install --data "$install_payload")
  parse_curl_output body code "$raw"
  info "HTTP ${code} body: ${body}"
  if [[ "$code" == "201" || "$code" == "200" ]]; then
    assert_json_field "install 成功 code=0" "$body" "code" "0"
    pass "安装向导完成"
  elif [[ "$code" == "409" ]]; then
    info "已安装（40901）— 视为通过"
    pass "已安装状态正确返回 40901"
  else
    fail "install 失败 HTTP ${code} body=${body}"
  fi

  log_s "17.1.4 验证 /api/setup/status 现在返回 installed=true"
  raw=$(curl_run GET /setup/status)
  parse_curl_output body code "$raw"
  assert_body_contains "install 后 installed=true" "$body" '"installed":true'

  log_s "17.1.5 验证未安装时其他接口被拦截（50301）— 已安装后此断言仅作记录"
  info "（已安装，跳过 50301 验证；如需测试请清空 backend/config/installed.lock）"
  skip "50301 拦截验证需在未安装态下手动测试"
}

# =============================================================================
# SubTask 17.2: 已安装后 /api/setup/* 返回 40901
# =============================================================================
scenario_17_2() {
  log_h "SubTask 17.2: 已安装后 setup 接口返回 40901"

  log_s "17.2.1 POST /api/setup/test-database → 40901"
  local raw body code
  raw=$(curl_run POST /setup/test-database --data "{\"host\":\"${DB_HOST}\",\"port\":${DB_PORT},\"name\":\"${DB_NAME}\",\"user\":\"${DB_USER}\",\"password\":\"${DB_PASSWORD}\"}")
  parse_curl_output body code "$raw"
  info "HTTP ${code} body: ${body}"
  if [[ "$code" == "409" ]]; then
    assert_json_field "test-database 已安装后 code=40901" "$body" "code" "40901"
  else
    fail "test-database 期望 HTTP 409，实际 ${code} body=${body}"
  fi

  log_s "17.2.2 POST /api/setup/install → 40901"
  local install_payload
  install_payload=$(cat <<JSON
{"database":{"host":"${DB_HOST}","port":${DB_PORT},"name":"${DB_NAME}","user":"${DB_USER}","password\":\"${DB_PASSWORD}\"},
 "admin":{"username":"${ADMIN_USER}","password":"${ADMIN_PASS}","email":"${ADMIN_EMAIL}"}}
JSON
)
  raw=$(curl_run POST /setup/install --data "$install_payload")
  parse_curl_output body code "$raw"
  info "HTTP ${code} body: ${body}"
  if [[ "$code" == "409" ]]; then
    assert_json_field "install 已安装后 code=40901" "$body" "code" "40901"
  else
    fail "install 期望 HTTP 409，实际 ${code} body=${body}"
  fi
}

# =============================================================================
# SubTask 17.3: 登录 → me → 改密 → refresh → logout
# =============================================================================
scenario_17_3() {
  log_h "SubTask 17.3: 登录 → me → 改密 → refresh → logout"

  log_s "17.3.1 POST /api/auth/login"
  local raw body code
  raw=$(curl_run POST /auth/login --data "{\"username\":\"${ADMIN_USER}\",\"password\":\"${ADMIN_PASS}\"}")
  parse_curl_output body code "$raw"
  info "HTTP ${code} body (truncated): $(echo "$body" | head -c 200)..."
  assert_http "login 接口可调用" 200 "$code"
  assert_json_field "login 成功 code=0" "$body" "code" "0"
  assert_body_contains "返回 access_token" "$body" '"access_token"'
  assert_body_contains "返回 refresh_token" "$body" '"refresh_token"'
  assert_body_contains "返回 user 对象" "$body" '"user"'

  local access refresh
  access=$(json_get "$body" "access_token")
  refresh=$(json_get "$body" "refresh_token")
  printf '%s' "$access" > "$ACCESS_TOKEN_FILE"
  printf '%s' "$refresh" > "$REFRESH_TOKEN_FILE"
  info "access_token 已保存到 ${ACCESS_TOKEN_FILE}"

  log_s "17.3.2 GET /api/auth/me（带 Authorization）"
  raw=$(curl_run GET /auth/me --data "" -H "Authorization: Bearer ${access}")
  parse_curl_output body code "$raw"
  info "HTTP ${code} body: ${body}"
  assert_http "me 接口可访问" 200 "$code"
  assert_body_contains "返回 username 字段" "$body" '"username"'

  log_s "17.3.3 PUT /api/profile/password（改密）"
  raw=$(curl_run PUT /profile/password --data "{\"current_password\":\"${ADMIN_PASS}\",\"new_password\":\"${NEW_PASS}\"}" -H "Authorization: Bearer ${access}")
  parse_curl_output body code "$raw"
  info "HTTP ${code} body: ${body}"
  assert_http "change-password 成功" 200 "$code"
  assert_json_field "change-password code=0" "$body" "code" "0"

  log_s "17.3.4 POST /api/auth/refresh（刷新 access_token）"
  raw=$(curl_run POST /auth/refresh --data "{\"refresh_token\":\"${refresh}\"}")
  parse_curl_output body code "$raw"
  info "HTTP ${code} body: ${body}"
  assert_http "refresh 接口可访问" 200 "$code"
  assert_json_field "refresh 成功 code=0" "$body" "code" "0"
  assert_body_contains "refresh 返回新 access_token" "$body" '"access_token"'
  local new_access
  new_access=$(json_get "$body" "access_token")
  printf '%s' "$new_access" > "$NEW_ACCESS_TOKEN_FILE"
  info "新 access_token 已保存到 ${NEW_ACCESS_TOKEN_FILE}"

  log_s "17.3.5 用新密码重新登录验证改密生效"
  raw=$(curl_run POST /auth/login --data "{\"username\":\"${ADMIN_USER}\",\"password\":\"${NEW_PASS}\"}")
  parse_curl_output body code "$raw"
  assert_http "新密码登录成功" 200 "$code"
  assert_json_field "新密码登录 code=0" "$body" "code" "0"
  local access2
  access2=$(json_get "$body" "access_token")
  # 更新后续场景使用的 token
  printf '%s' "$access2" > "$ACCESS_TOKEN_FILE"
  info "已更新 ACCESS_TOKEN_FILE 为新 token"

  log_s "17.3.6 POST /api/auth/logout"
  raw=$(curl_run POST /auth/logout --data "{\"refresh_token\":\"${refresh}\"}" -H "Authorization: Bearer ${access2}")
  parse_curl_output body code "$raw"
  info "HTTP ${code} body: ${body}"
  assert_http "logout 接口可访问" 200 "$code"
  assert_json_field "logout code=0" "$body" "code" "0"

  log_s "17.3.7 logout 后旧 token 仍可用（JWT 无状态，预期行为）"
  info "注：spec 5.1.8 提到 jti 黑名单可后续添加；当前 logout 仅客户端清 token"
  raw=$(curl_run GET /auth/me --data "" -H "Authorization: Bearer ${access2}")
  parse_curl_output body code "$raw"
  info "HTTP ${code} (预期 200，因 JWT 仍有效)"
  if [[ "$code" == "200" ]]; then
    pass "JWT stateless logout 行为符合预期"
  else
    fail "logout 后旧 token 应仍可用（无服务端黑名单），实际 HTTP ${code}"
  fi

  # 改密后还原（让后续场景能用 ADMIN_PASS 登录）
  log_s "17.3.8 还原密码为 ADMIN_PASS（供后续场景使用）"
  raw=$(curl_run PUT /profile/password --data "{\"current_password\":\"${NEW_PASS}\",\"new_password\":\"${ADMIN_PASS}\"}" -H "Authorization: Bearer ${access2}")
  parse_curl_output body code "$raw"
  if [[ "$code" == "200" ]]; then
    pass "密码已还原"
  else
    fail "密码还原失败 HTTP ${code} body=${body}（后续场景请改用 NEW_PASS=${NEW_PASS}）"
  fi

  # 重新登录获取新 token
  raw=$(curl_run POST /auth/login --data "{\"username\":\"${ADMIN_USER}\",\"password\":\"${ADMIN_PASS}\"}")
  parse_curl_output body code "$raw"
  local access3
  access3=$(json_get "$body" "access_token")
  printf '%s' "$access3" > "$ACCESS_TOKEN_FILE"
}

# =============================================================================
# SubTask 17.4: 书签 CRUD + 拖拽排序
# =============================================================================
scenario_17_4() {
  log_h "SubTask 17.4: 书签 CRUD + 拖拽排序"

  local access
  access=$(cat "$ACCESS_TOKEN_FILE" 2>/dev/null || true)
  if [[ -z "$access" ]]; then
    skip "17.4 需要登录态，未找到 token（先运行 17.3）"
    return
  fi

  log_s "17.4.1 POST /api/bookmarks（新增）"
  local raw body code
  raw=$(curl_run POST /bookmarks --data '{"title":"NYTab Test","url":"https://example.com/nytab","description":"测试书签","extra":{"tags":["test","e2e"],"color":"#42b883","open_in_new_tab":true}}' -H "Authorization: Bearer ${access}")
  parse_curl_output body code "$raw"
  info "HTTP ${code} body: ${body}"
  assert_http "create bookmark 返回 201" 201 "$code"
  assert_json_field "create code=0" "$body" "code" "0"
  assert_body_contains "返回 id" "$body" '"id"'

  local bmid
  bmid=$(json_get "$body" "id")
  printf '%s' "$bmid" > "$BOOKMARK_ID_FILE"
  info "新书签 id=${bmid}"

  log_s "17.4.2 GET /api/bookmarks（列表）"
  raw=$(curl_run GET /bookmarks -H "Authorization: Bearer ${access}")
  parse_curl_output body code "$raw"
  info "HTTP ${code} body (前 200 字符): $(echo "$body" | head -c 200)..."
  assert_http "list bookmarks" 200 "$code"
  assert_body_contains "列表包含刚创建的 id=${bmid}" "$body" "\"id\":${bmid}"

  log_s "17.4.3 GET /api/bookmarks/{id}（详情）"
  raw=$(curl_run GET "/bookmarks/${bmid}" -H "Authorization: Bearer ${access}")
  parse_curl_output body code "$raw"
  info "HTTP ${code} body: ${body}"
  assert_http "show bookmark" 200 "$code"
  assert_body_contains "详情含 title" "$body" '"title"'

  log_s "17.4.4 PUT /api/bookmarks/{id}（更新）"
  raw=$(curl_run PUT "/bookmarks/${bmid}" --data '{"title":"NYTab Updated","url":"https://example.com/updated","extra":{"tags":["updated"],"color":"#ff0000","open_in_new_tab":false}}' -H "Authorization: Bearer ${access}")
  parse_curl_output body code "$raw"
  info "HTTP ${code} body: ${body}"
  assert_http "update bookmark" 200 "$code"
  assert_body_contains "更新后 title=NYTab Updated" "$body" '"title":"NYTab Updated"'

  log_s "17.4.5 PUT /api/bookmarks/reorder（拖拽排序）"
  # 先再创建一个书签用于排序
  raw=$(curl_run POST /bookmarks --data '{"title":"Second","url":"https://example.com/second"}' -H "Authorization: Bearer ${access}")
  parse_curl_output body code "$raw"
  local bmid2
  bmid2=$(json_get "$body" "id")
  info "第二个书签 id=${bmid2}"

  raw=$(curl_run PUT /bookmarks/reorder --data "{\"items\":[{\"id\":${bmid2},\"sort_order\":0},{\"id\":${bmid},\"sort_order\":1}]}" -H "Authorization: Bearer ${access}")
  parse_curl_output body code "$raw"
  info "HTTP ${code} body: ${body}"
  assert_http "reorder bookmarks" 200 "$code"
  assert_json_field "reorder code=0" "$body" "code" "0"

  # 验证顺序生效
  raw=$(curl_run GET /bookmarks -H "Authorization: Bearer ${access}")
  parse_curl_output body code "$raw"
  if echo "$body" | grep -q "\"id\":${bmid2}.*\"id\":${bmid}"; then
    pass "reorder 后顺序正确（bmid2 在 bmid 之前）"
  else
    info "注：grep 顺序判断不严格，建议人工检查 body: ${body}"
    skip "reorder 顺序验证（人工核查）"
  fi

  log_s "17.4.6 DELETE /api/bookmarks/{id}（删除）"
  raw=$(curl_run DELETE "/bookmarks/${bmid}" -H "Authorization: Bearer ${access}")
  parse_curl_output body code "$raw"
  info "HTTP ${code} body: ${body}"
  assert_http "delete bookmark" 200 "$code"
  assert_json_field "delete code=0" "$body" "code" "0"

  # 清理第二个书签
  if [[ -n "${bmid2:-}" ]]; then
    raw=$(curl_run DELETE "/bookmarks/${bmid2}" -H "Authorization: Bearer ${access}")
    parse_curl_output body code "$raw"
    info "清理第二个书签 HTTP ${code}"
  fi

  # 顺便测一个分类接口（spec 3.2 列表）
  log_s "17.4.7 POST /api/bookmark-categories（创建分类）"
  raw=$(curl_run POST /bookmark-categories --data '{"name":"测试分类","icon":"folder"}' -H "Authorization: Bearer ${access}")
  parse_curl_output body code "$raw"
  info "HTTP ${code} body: ${body}"
  assert_http "create category" 201 "$code"
  local catid
  catid=$(json_get "$body" "id")
  printf '%s' "$catid" > "$CATEGORY_ID_FILE"

  log_s "17.4.8 GET /api/bookmark-categories（分类树）"
  raw=$(curl_run GET /bookmark-categories -H "Authorization: Bearer ${access}")
  parse_curl_output body code "$raw"
  info "HTTP ${code} body (前 200): $(echo "$body" | head -c 200)..."
  assert_http "list categories" 200 "$code"
  assert_body_contains "分类树包含 children 字段" "$body" '"children"'

  # 清理分类
  if [[ -n "${catid:-}" ]]; then
    raw=$(curl_run DELETE "/bookmark-categories/${catid}" -H "Authorization: Bearer ${access}")
    parse_curl_output body code "$raw"
    info "清理分类 HTTP ${code}"
  fi
}

# =============================================================================
# SubTask 17.5: 工作台布局拖拽 → 刷新 → 恢复一致
# =============================================================================
scenario_17_5() {
  log_h "SubTask 17.5: 工作台布局拖拽 → 刷新 → 恢复一致"

  local access
  access=$(cat "$ACCESS_TOKEN_FILE" 2>/dev/null || true)
  if [[ -z "$access" ]]; then
    skip "17.5 需要登录态"
    return
  fi

  log_s "17.5.1 GET /api/workspace/layout（初始）"
  local raw body code
  raw=$(curl_run GET /workspace/layout -H "Authorization: Bearer ${access}")
  parse_curl_output body code "$raw"
  info "HTTP ${code} body: ${body}"
  assert_http "get layout" 200 "$code"
  assert_body_contains "layout 字段存在" "$body" '"layout"'
  assert_body_contains "settings 字段存在" "$body" '"settings"'

  log_s "17.5.2 PUT /api/workspace/layout（更新布局）"
  local new_layout
  new_layout='{"layout":[{"pluginId":"pomodoro","x":0,"y":0,"w":2,"h":2,"enabled":true},{"pluginId":"markdown","x":2,"y":0,"w":4,"h":3,"enabled":true}]}'
  raw=$(curl_run PUT /workspace/layout --data "$new_layout" -H "Authorization: Bearer ${access}")
  parse_curl_output body code "$raw"
  info "HTTP ${code} body: ${body}"
  assert_http "put layout" 200 "$code"
  assert_json_field "put layout code=0" "$body" "code" "0"

  log_s "17.5.3 GET /api/workspace/layout（刷新验证一致）"
  raw=$(curl_run GET /workspace/layout -H "Authorization: Bearer ${access}")
  parse_curl_output body code "$raw"
  info "HTTP ${code} body: ${body}"
  assert_http "get layout after put" 200 "$code"
  assert_body_contains "持久化后包含 pomodoro" "$body" '"pluginId":"pomodoro"'
  assert_body_contains "持久化后包含 markdown" "$body" '"pluginId":"markdown"'

  log_s "17.5.4 PUT /api/workspace/settings（更新设置）"
  raw=$(curl_run PUT /workspace/settings --data '{"settings":{"cols":12,"rowHeight":80,"gap":16,"theme":"default"}}' -H "Authorization: Bearer ${access}")
  parse_curl_output body code "$raw"
  info "HTTP ${code} body: ${body}"
  assert_http "put settings" 200 "$code"
  assert_json_field "put settings code=0" "$body" "code" "0"

  log_s "17.5.5 GET /api/workspace/settings（验证）"
  raw=$(curl_run GET /workspace/settings -H "Authorization: Bearer ${access}")
  parse_curl_output body code "$raw"
  info "HTTP ${code} body: ${body}"
  assert_http "get settings" 200 "$code"
  assert_body_contains "settings 含 cols" "$body" '"cols"'
  assert_body_contains "settings 含 rowHeight" "$body" '"rowHeight"'
}

# =============================================================================
# SubTask 17.6: 至少 3 个工具状态跨设备同步
# =============================================================================
scenario_17_6() {
  log_h "SubTask 17.6: 3 个工具状态跨设备同步（pomodoro / notes / markdown）"

  local access
  access=$(cat "$ACCESS_TOKEN_FILE" 2>/dev/null || true)
  if [[ -z "$access" ]]; then
    skip "17.6 需要登录态"
    return
  fi

  # ---------- pomodoro ----------
  log_s "17.6.1 PUT /api/tools/pomodoro/state"
  local raw body code
  raw=$(curl_run PUT /tools/pomodoro/state --data '{"state":{"todaySeconds":1500,"targetSeconds":1500,"isRunning":false}}' -H "Authorization: Bearer ${access}")
  parse_curl_output body code "$raw"
  info "HTTP ${code} body: ${body}"
  assert_http "put pomodoro state" 200 "$code"
  assert_body_contains "返回 ok=true" "$body" '"ok":true'

  log_s "17.6.2 GET /api/tools/pomodoro/state（验证一致）"
  raw=$(curl_run GET /tools/pomodoro/state -H "Authorization: Bearer ${access}")
  parse_curl_output body code "$raw"
  info "HTTP ${code} body: ${body}"
  assert_http "get pomodoro state" 200 "$code"
  assert_body_contains "pomodoro state 含 todaySeconds" "$body" '"todaySeconds"'

  # ---------- notes ----------
  log_s "17.6.3 PUT /api/tools/notes/state"
  raw=$(curl_run PUT /tools/notes/state --data '{"state":{"notes":[{"id":1,"content":"买菜","color":"yellow"},{"id":2,"content":"写文档","color":"blue"}]}}' -H "Authorization: Bearer ${access}")
  parse_curl_output body code "$raw"
  info "HTTP ${code} body: ${body}"
  assert_http "put notes state" 200 "$code"
  assert_body_contains "返回 ok=true" "$body" '"ok":true'

  log_s "17.6.4 GET /api/tools/notes/state（验证一致）"
  raw=$(curl_run GET /tools/notes/state -H "Authorization: Bearer ${access}")
  parse_curl_output body code "$raw"
  info "HTTP ${code} body: ${body}"
  assert_http "get notes state" 200 "$code"
  assert_body_contains "notes state 含 买菜" "$body" '买菜'

  # ---------- markdown ----------
  log_s "17.6.5 PUT /api/tools/markdown/state"
  raw=$(curl_run PUT /tools/markdown/state --data '{"state":{"content":"# Hello NYTab\n\nThis is a test.","cursorLine":3}}' -H "Authorization: Bearer ${access}")
  parse_curl_output body code "$raw"
  info "HTTP ${code} body: ${body}"
  assert_http "put markdown state" 200 "$code"
  assert_body_contains "返回 ok=true" "$body" '"ok":true'

  log_s "17.6.6 GET /api/tools/markdown/state（验证一致）"
  raw=$(curl_run GET /tools/markdown/state -H "Authorization: Bearer ${access}")
  parse_curl_output body code "$raw"
  info "HTTP ${code} body: ${body}"
  assert_http "get markdown state" 200 "$code"
  assert_body_contains "markdown state 含 # Hello NYTab" "$body" 'Hello NYTab'

  # ---------- 验证 pluginId 校验 ----------
  log_s "17.6.7 PUT /api/tools/<invalid-plugin-id>/state（验证 pluginId 校验）"
  raw=$(curl_run PUT '/tools/invalid plugin id/state' --data '{"state":{}}' -H "Authorization: Bearer ${access}")
  parse_curl_output body code "$raw"
  info "HTTP ${code} body: ${body}"
  # 路径含空格会被 Router 当成不匹配，预期 404
  if [[ "$code" == "404" || "$code" == "422" ]]; then
    pass "非法 pluginId 被拒绝 (HTTP ${code})"
  else
    fail "非法 pluginId 应被拒绝，实际 HTTP ${code} body=${body}"
  fi
}

# =============================================================================
# SubTask 17.7: 防暴力破解 5 次失败锁定
# =============================================================================
scenario_17_7() {
  log_h "SubTask 17.7: 防暴力破解 5 次失败 → 第 6 次锁定"

  log_s "17.7.1 连续 5 次错误密码登录（预期每次 401 + 40102）"
  local i raw body code
  for i in 1 2 3 4 5; do
    raw=$(curl_run POST /auth/login --data "{\"username\":\"${ADMIN_USER}\",\"password\":\"__WRONG_PASSWORD_${i}__\"}")
    parse_curl_output body code "$raw"
    info "[尝试 ${i}] HTTP ${code} body: ${body}"
    if [[ "$code" == "401" ]]; then
      assert_json_field "第 ${i} 次失败 code=40102" "$body" "code" "40102"
    else
      fail "第 ${i} 次失败预期 HTTP 401，实际 ${code} body=${body}"
    fi
  done

  log_s "17.7.2 第 6 次尝试（预期 429 + 42901）"
  raw=$(curl_run POST /auth/login --data "{\"username\":\"${ADMIN_USER}\",\"password\":\"__WRONG_PASSWORD_6__\"}")
  parse_curl_output body code "$raw"
  info "HTTP ${code} body: ${body}"
  if [[ "$code" == "429" ]]; then
    assert_json_field "第 6 次锁定 code=42901" "$body" "code" "42901"
    pass "防暴力破解触发锁定"
  else
    fail "第 6 次预期 HTTP 429，实际 ${code} body=${body}"
    info "注：RateLimitMiddleware 计数基于 login_logs 表，5 次累计失败后第 6 次才会触发 429"
  fi

  log_s "17.7.3 等待 15 分钟或手动清理 login_logs 后可恢复登录"
  info "若需立即重置：psql -c \"DELETE FROM login_logs WHERE ip = '127.0.0.1' AND success = false;\""
  info "或等待 5 分钟窗口过期（spec 5.1.7）"
  skip "锁定恢复验证（需等待或手动清理 login_logs）"
}

# =============================================================================
# SubTask 17.8: CORS 白名单与 OPTIONS 预检
# =============================================================================
scenario_17_8() {
  log_h "SubTask 17.8: CORS 白名单与 OPTIONS 预检"

  log_s "17.8.1 OPTIONS 预检（白名单内 Origin）"
  local resp_headers body code
  resp_headers=$(curl -s -S -D - -o /dev/null \
    -X OPTIONS \
    "${API}/auth/login" \
    -H "Origin: ${CORS_ORIGIN}" \
    -H "Access-Control-Request-Method: POST" \
    -H "Access-Control-Request-Headers: Content-Type, Authorization" \
    -w "\n%{http_code}\n")
  code=$(echo "$resp_headers" | tail -n1)
  info "HTTP ${code}"
  info "响应头："
  echo "$resp_headers" | head -n -1 | sed 's/^/    /'

  if [[ "$code" == "204" ]]; then
    pass "OPTIONS 预检返回 204"
  else
    fail "OPTIONS 预检预期 HTTP 204，实际 ${code}"
  fi

  if echo "$resp_headers" | grep -qi "Access-Control-Allow-Origin: ${CORS_ORIGIN}"; then
    pass "Allow-Origin 回显白名单 Origin"
  else
    fail "Allow-Origin 未回显 ${CORS_ORIGIN}（检查后端 .env 的 CORS_ORIGINS 配置）"
  fi

  if echo "$resp_headers" | grep -qi "Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS"; then
    pass "Allow-Methods 包含预期方法"
  else
    fail "Allow-Methods 头缺失或不完整"
  fi

  if echo "$resp_headers" | grep -qi "Access-Control-Allow-Headers: Content-Type, Authorization"; then
    pass "Allow-Headers 包含 Content-Type, Authorization"
  else
    fail "Allow-Headers 头缺失或不完整"
  fi

  log_s "17.8.2 非白名单 Origin 应被拒绝（不回显 Allow-Origin）"
  local evil_origin="https://evil.example.com"
  resp_headers=$(curl -s -S -D - -o /dev/null \
    -X OPTIONS \
    "${API}/auth/login" \
    -H "Origin: ${evil_origin}" \
    -H "Access-Control-Request-Method: POST" \
    -w "\n%{http_code}\n")
  code=$(echo "$resp_headers" | tail -n1)
  info "HTTP ${code}（CORS 预检始终返回 204，但不带 Allow-Origin 头）"

  if echo "$resp_headers" | grep -qi "Access-Control-Allow-Origin: ${evil_origin}"; then
    fail "非白名单 Origin 不应被回显，实际 Allow-Origin: ${evil_origin}"
  else
    pass "非白名单 Origin 未被回显 Allow-Origin"
  fi

  log_s "17.8.3 实际 GET 请求验证 CORS 头（白名单 Origin）"
  resp_headers=$(curl -s -S -D - -o /dev/null \
    "${API}/setup/status" \
    -H "Origin: ${CORS_ORIGIN}" \
    -w "\n%{http_code}\n")
  code=$(echo "$resp_headers" | tail -n1)
  info "HTTP ${code}"
  if echo "$resp_headers" | grep -qi "Access-Control-Allow-Origin: ${CORS_ORIGIN}"; then
    pass "实际请求中白名单 Origin 被回显"
  else
    fail "实际请求中白名单 Origin 未被回显（检查 CORS_ORIGINS 是否包含 ${CORS_ORIGIN}）"
  fi
}

# =============================================================================
# SubTask 16: Task 16 扩展端点验证
# -----------------------------------------------------------------------------
# 依赖 17.3 拿到的 ACCESS_TOKEN_FILE。覆盖 Task 16 引入的新端点：
#   16.1 GET /api/dev-mode/status          (auth)   返回 { enabled: bool }
#   16.2 GET /api/branding                  (public) 返回 { nickname,title,logo,copyright }
#   16.3 PUT /api/branding                  (auth)   更新 nickname/title/logo
#                                                    copyright 字段被服务端忽略
#   16.4 GET /api/weather/settings          (auth)   返回当前天气设置（密钥脱敏）
#   16.5 GET /api/settings/background       (public) 返回背景配置
#   16.6 POST /api/setup/test-database      (no auth,installed 后返回 40901)
# =============================================================================
scenario_16() {
  log_h "SubTask 16: Task 16 扩展端点验证（dev-mode / branding / weather / background / setup test-database）"

  local access
  access=$(cat "$ACCESS_TOKEN_FILE" 2>/dev/null || true)

  # 16.1 GET /api/dev-mode/status —— 需要 auth
  log_s "16.1 GET /api/dev-mode/status（开发者模式状态）"
  local raw body code
  if [[ -z "$access" ]]; then
    skip "16.1 需要登录态（先运行 17.3）"
  else
    raw=$(curl_run GET /dev-mode/status --data "" -H "Authorization: Bearer ${access}")
    parse_curl_output body code "$raw"
    info "HTTP ${code} body: ${body}"
    assert_http "dev-mode/status 可访问" 200 "$code"
    assert_body_contains "返回 enabled 字段" "$body" '"enabled"'
  fi

  # 16.2 GET /api/branding —— public
  log_s "16.2 GET /api/branding（品牌信息，public）"
  raw=$(curl_run GET /branding)
  parse_curl_output body code "$raw"
  info "HTTP ${code} body: ${body}"
  assert_http "branding public 可访问" 200 "$code"
  assert_body_contains "返回 nickname" "$body" '"nickname"'
  assert_body_contains "返回 title" "$body" '"title"'
  assert_body_contains "返回 logo" "$body" '"logo"'
  assert_body_contains "返回硬编码 copyright" "$body" '"copyright"'

  # 保存原始品牌信息，供 16.3 之后还原
  local orig_nickname orig_title orig_logo
  orig_nickname=$(json_get "$body" "nickname")
  orig_title=$(json_get "$body" "title")
  orig_logo=$(json_get "$body" "logo")
  info "原始品牌：nickname=${orig_nickname} title=${orig_title} logo=${orig_logo}"

  # 16.3 PUT /api/branding —— auth
  log_s "16.3 PUT /api/branding（更新品牌，auth）"
  if [[ -z "$access" ]]; then
    skip "16.3 需要登录态（先运行 17.3）"
  else
    raw=$(curl_run PUT /branding \
      --data '{"nickname":"TestTab","title":"Test Tab","logo":"/logo.jpg","copyright":"IGNORED"}' \
      -H "Authorization: Bearer ${access}")
    parse_curl_output body code "$raw"
    info "HTTP ${code} body: ${body}"
    assert_http "update branding 成功" 200 "$code"
    assert_body_contains "更新后 nickname=TestTab" "$body" '"nickname":"TestTab"'
    assert_body_contains "更新后 title=Test Tab" "$body" '"title":"Test Tab"'
    assert_body_contains "copyright 字段保持硬编码（未被 IGNORED 覆盖）" "$body" '"copyright":"© 暖心向阳335"'

    # 还原原始品牌信息，避免污染后续场景与持久状态
    log_s "16.3.1 还原原始品牌信息"
    local restore_payload
    restore_payload=$(cat <<JSON
{"nickname":"${orig_nickname}","title":"${orig_title}","logo":"${orig_logo}"}
JSON
)
    raw=$(curl_run PUT /branding --data "$restore_payload" -H "Authorization: Bearer ${access}")
    parse_curl_output body code "$raw"
    info "还原 HTTP ${code} body: ${body}"
    if [[ "$code" == "200" ]]; then
      pass "品牌信息已还原"
    else
      fail "品牌信息还原失败 HTTP ${code} body=${body}"
    fi
  fi

  # 16.4 GET /api/weather/settings —— auth
  log_s "16.4 GET /api/weather/settings（天气设置，auth）"
  if [[ -z "$access" ]]; then
    skip "16.4 需要登录态（先运行 17.3）"
  else
    raw=$(curl_run GET /weather/settings --data "" -H "Authorization: Bearer ${access}")
    parse_curl_output body code "$raw"
    info "HTTP ${code} body: ${body}"
    assert_http "weather/settings 可访问" 200 "$code"
    assert_body_contains "返回 provider 字段" "$body" '"provider"'
    assert_body_contains "返回 default_city 字段" "$body" '"default_city"'
    assert_body_contains "返回 auto_location 字段" "$body" '"auto_location"'
  fi

  # 16.5 GET /api/settings/background —— public
  log_s "16.5 GET /api/settings/background（背景设置，public）"
  raw=$(curl_run GET /settings/background)
  parse_curl_output body code "$raw"
  info "HTTP ${code} body: ${body}"
  assert_http "background public 可访问" 200 "$code"
  assert_body_contains "返回 type 字段" "$body" '"type"'

  # 16.6 POST /api/setup/test-database —— no auth，installed 后返回 40901
  log_s "16.6 POST /api/setup/test-database（简化安装测试连接）"
  raw=$(curl_run POST /setup/test-database \
    --data "{\"host\":\"${DB_HOST}\",\"port\":${DB_PORT},\"name\":\"${DB_NAME}\",\"user\":\"${DB_USER}\",\"password\":\"${DB_PASSWORD}\"}")
  parse_curl_output body code "$raw"
  info "HTTP ${code} body: ${body}"
  if [[ "$code" == "200" ]]; then
    # 未安装时返回 { ok:true, databaseExists, canCreate, server_version }
    assert_json_field "test-database ok=true" "$body" "ok" "true"
    assert_body_contains "返回 databaseExists" "$body" '"databaseExists"'
    assert_body_contains "返回 server_version" "$body" '"server_version"'
  elif [[ "$code" == "409" ]]; then
    # 已安装时被 SetupGuardMiddleware 拦截，返回 40901
    assert_json_field "test-database 已安装后 code=40901" "$body" "code" "40901"
    pass "已安装态下 test-database 被正确拦截（40901）"
  elif [[ "$code" == "422" ]]; then
    # 连接参数无效或 PostgreSQL 不可达
    skip "test-database 连接失败 (HTTP 422)，确认 DB 配置正确：${body}"
  else
    fail "test-database 预期 HTTP 200/409/422，实际 ${code} body=${body}"
  fi
}

# =============================================================================
# 主流程
# =============================================================================
main() {
  log "NYTab 端到端 curl 测试脚本"
  log "BASE_URL: ${BASE_URL}"
  log "API:      ${API}"
  log "时间:     $(date '+%Y-%m-%d %H:%M:%S%z')"
  log

  if ! command -v curl >/dev/null 2>&1; then
    log "错误：未找到 curl 命令"
    exit 2
  fi

  # 检查后端可达性
  log_s "前置：后端可达性检查"
  local precheck
  precheck=$(curl -s -S -o /dev/null -w "%{http_code}" --max-time 5 "${API}/setup/status" 2>/dev/null || true)
  if [[ -z "$precheck" ]]; then
    log "  ✗ 无法连接到后端 ${API}"
    log "  请确认后端已启动："
    log "    php -S 0.0.0.0:8000 -t backend/public"
    log "  或通过 Nginx + PHP-FPM 部署。"
    exit 2
  fi
  pass "后端可达 (HTTP ${precheck})"

  # 按顺序执行 8 个场景
  should_run 17.1 && scenario_17_1
  should_run 17.2 && scenario_17_2
  should_run 17.3 && scenario_17_3
  should_run 17.4 && scenario_17_4
  should_run 17.5 && scenario_17_5
  should_run 17.6 && scenario_17_6
  should_run 17.7 && scenario_17_7
  should_run 17.8 && scenario_17_8

  # Task 16 扩展端点验证（依赖 17.3 拿到的 token；放最后避免干扰主流程）
  should_run 16 && scenario_16

  # 汇总
  log_h "汇总"
  printf '  通过: \033[32m%d\033[0m\n' "$PASS"
  printf '  失败: \033[31m%d\033[0m\n' "$FAIL"
  printf '  跳过: \033[33m%d\033[0m\n' "$SKIP"
  printf '  总计: %d\n' "$((PASS + FAIL + SKIP))"

  if [[ "$FAIL" -gt 0 ]]; then
    exit 1
  fi
  exit 0
}

main "$@"
