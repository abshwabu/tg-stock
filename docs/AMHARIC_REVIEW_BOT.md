# Amharic Telegram bot review

Native-speaker review required before release. The table is generated from `server/lang/am.json`; hardcoded Telegram strings should be moved there when touched.

| Key | Amharic text |
|---|---|
| `welcome_start` | 👋 እንኳን ወደ <b>ኢንቬንቶሪ</b> በደህና መጡ!<br>ለሱቅዎ ቀላል እና ፈጣን የዕቃና ሽያጭ መቆጣጠሪያ። |
| `welcome_existing` | 👋 እንኳን ደህና መጡ <b>:name</b>!<br>🏪 <b>:shop</b> |
| `onboarding_ask_shop_name` | 🏪 እንኳን ደህና መጡ! ለመጀመር እባክዎ የሱቅዎን <b>ስም</b> ያስገቡ: |
| `onboarding_shop_created` | 🎉 <b>:name</b> ሱቅ በተሳካ ሁኔታ ተፈጥሯል!<br>እርስዎ የሱቁ ባለቤት ሆነው ተመዝግበዋል። |
| `onboarding_invalid_name` | የሱቅ ስም ቢያንስ 2 ፊደላት መሆን አለበት። እባክዎ እንደገና ያስገቡ: |
| `pair_ask_confirmation` | 📱 <b>:device</b> የተሰኘውን ስልክ ከ <b>:shop</b> ጋር ማገናኘት ይፈልጋሉ? |
| `pair_success` | ✅ <b>:device</b> ስልክ በተሳካ ሁኔታ ተገናኝቷል!<br>የስልክ መተግበሪያዎ አሁን ከሱቅዎ ጋር ተገናኝቷል። |
| `pair_invalid_code` | ❌ ይህ የማጣመሪያ ኮድ ልክ ያልሆነ ወይም ጊዜው ያለፈበት ነው። እባክዎ ከመተግበሪያው አዲስ ኮድ ያውጡ። |
| `pair_cancelled` | ማጣመሩ ተሰርዟል። |
| `action_cancelled` | ❌ ተግባሩ ተሰርዟል። ወደ ዋና ማውጫ ተመልሰዋል። |
| `menu_products` | 📦 ዕቃዎች |
| `menu_stock_in` | ➕ ገቢ ዕቃ |
| `menu_sale` | 🛒 ሽያጭ |
| `menu_reports` | 📊 ሪፖርት |
| `menu_settings` | ⚙️ ቅንብሮች |
| `menu_overview` | 🏪 <b>:shop</b><br>ከታች ካሉት አማራጮች አንዱን ይምረጡ: |
| `settings_title` | ⚙️ <b>ቅንብሮች</b><br>🏪 ሱቅ: <b>:shop</b><br>👤 ሚና: <b>:role</b><br>🌐 ቋንቋ: <b>:language</b><br>⚡ ከዜሮ በታች ሽያጭ: <b>:neg_stock</b> |
| `choose_language` | 🌐 ቋንቋ ይምረጡ / Choose language: |
| `language_updated` | ✅ ቋንቋው በተሳካ ሁኔታ ወደ አማርኛ ተቀይሯል! |
| `help_text` | ℹ️ <b>ፈጣን መመሪያ</b><br>1. ከዕቃዎች ማውጫ ምርት ይጨምሩ (ወይም በስም ይፈልጉ)።<br>2. በሽያጭ ማውጫ ጥሬ ገንዘብ፣ ባንክ፣ ዋሌት ወይም ዱቤ ሽያጭ ይመዝግቡ።<br>3. ሽያጭ፣ ስቶክ እና ሂሳብ ለማየት ሪፖርት ይክፈቱ።<br>4. ባለቤቱ ከቅንብሮች → ቡድን ሠራተኞችን ይጋብዛል።<br>5. ማንኛውንም ደረጃ ለማቋረጥ /cancel ይጻፉ።<br><br>📱 የስልክ መተግበሪያ: https://stock.yenesuq.com/app/<br>🔒 ግላዊነት: https://stock.yenesuq.com/privacy/<br>📜 ደንቦች: https://stock.yenesuq.com/terms/<br>💬 ድጋፍ: support@yenesuq.com |
| `unknown_command` | ይህን አማራጭ አላወቅሁትም። /menu ይጻፉ ወይም የዕቃ ስም በመጻፍ ይፈልጉ።<br>ለፈጣን መመሪያ /help ይጻፉ። |
| `delete_account_owner_only` | ⛔ የኦንላይን ሱቅ መረጃን መሰረዝ የሚችለው ባለቤቱ ብቻ ነው። |
| `delete_account_first` | ⚠️ ይህ የሱቅዎን የኦንላይን ዕቃ፣ ስቶክ፣ ሽያጭ፣ ክፍያ፣ ስልኮች እና ቴሌግራም መረጃ ይሰርዛል። በስልኮች ላይ ያለው ኦፍላይን መረጃ አይሰረዝም።<br><br>ፖሊሲ: https://stock.yenesuq.com/privacy/<br>ድጋፍ: support@yenesuq.com<br><br>ወደ ሁለተኛ ማረጋገጫ ይቀጥሉ? |
| `delete_account_second` | ⚠️ የመጨረሻ ማረጋገጫ፦ ለ <b>:shop</b> የኦንላይን መረጃ በሙሉ እንዲሰረዝ ይፈልጋሉ? ይህ አይመለስም። |
| `delete_account_done` | ✅ የኦንላይን ሱቅ መረጃ ተሰርዟል። በስልክዎ ላይ ያለው ኦፍላይን መረጃ እንዳለ ነው። እርዳታ ከፈለጉ support@yenesuq.com ያግኙ። |
| `delete_account_cancelled` | ስረዛው ተሰርዟል። ምንም የኦንላይን መረጃ አልተቀየረም። |
| `invalid_number` | ⚠️ እባክዎ ትክክለኛ ቁጥር ያስገቡ (ለምሳሌ 10 ወይም 2.5): |
| `rate_limited` | ⏳ እባክዎ ትንሽ ቆይተው እንደገና ይሞክሩ። |
| `permission_denied` | ⛔ ይህን ለማድረግ የባለቤት ፈቃድ ያስፈልጋል። |
| `products_empty` | 📦 ምንም የተመዘገበ ዕቃ የለም። አዲስ ዕቃ ለመመዝገብ ከታች ያለውን ይጫኑ! |
| `products_title` | 📦 <b>የዕቃዎች ዝርዝር (:total)</b> |
| `product_card` | 📦 <b>:name</b><br>• መሸጫ: <b>:sell_price ብር</b><br>• መግዣ: :cost_price ብር<br>• ስቶክ: <b>:stock :unit</b> :badge<br>• ባርኮድ/SKU: :sku |
| `stock_badge_ok` | 🟢 |
| `stock_badge_low` | 🔴 አነስተኛ ስቶክ |
| `btn_stock_in` | ➕ ገቢ ዕቃ |
| `btn_sale` | 🛒 ሽያጭ |
| `btn_edit` | ✏️ አርትዕ |
| `btn_delete` | 🗑 ሰርዝ |
| `btn_adjust` | 🔧 አስተካክል |
| `btn_add_product` | ➕ አዲስ ዕቃ መዝግብ |
| `btn_skip` | ⏩ ዝለል |
| `add_product_name` | 📝 የዕቃውን <b>ስም</b> ያስገቡ: |
| `add_product_sell_price` | 💵 የዕቃውን <b>የመሸጫ ዋጋ</b> በብር ያስገቡ (ለምሳሌ 120 ወይም 25.50): |
| `add_product_opening_stock` | 📦 በአሁኑ ሰዓት ያለውን <b>መነሻ ብዛት</b> ያስገቡ (ከሌለ 'ዝለል' ይጫኑ): |
| `add_product_unit` | 📏 <b>የመለኪያ ዓይነት</b> ይምረጡ: |
| `add_product_cost_price` | 💰 የዕቃውን <b>የመግዣ ዋጋ</b> በብር ያስገቡ (ወይም 'ዝለል' ይጫኑ): |
| `add_product_success` | ✅ <b>:name</b> በተሳካ ሁኔታ ተመዝግቧል! |
| `quick_add_offer` | 💡 <b>ፈጣን ዕቃ መመዝገቢያ?</b><br>• ስም: <b>:name</b><br>• መሸጫ ዋጋ: <b>:sell_price ብር</b><br>• መነሻ ስቶክ: <b>:stock ፍሬ</b> |
| `btn_quick_create` | ➕ መዝግብ :name |
| `btn_just_search` | 🔍 ፈልግ ብቻ |
| `stock_in_ask_qty` | ➕ <b>ገቢ ዕቃ: :product</b><br>ያለ ስቶክ: <b>:stock :unit</b><br>የገባውን ብዛት ይምረጡ ወይም ያስገቡ: |
| `stock_in_ask_cost` | 💰 አማራጭ: አዲስ የመግዣ ዋጋ በብር ያስገቡ (ወይም ዝለል ይጫኑ): |
| `stock_in_success` | ✅ <b>ገቢ ዕቃ ተመዝግቧል!</b><br>📦 ዕቃ: <b>:product</b><br>➕ የገባ: +:added :unit<br>📊 አዲስ ስቶክ: <b>:new_stock :unit</b> |
| `sale_choose_product` | 🛒 <b>ሽያጭ መዝግብ</b><br>ከታች ካሉት ዕቃዎች ይምረጡ ወይም ለመፈለግ ስሙን ይጻፉ: |
| `sale_ask_qty` | 🛒 <b>ሽያጭ: :product</b><br>ዋጋ: <b>:price ብር</b><br>ያለ ስቶክ: <b>:stock :unit</b><br>የተሸጠውን ብዛት ይምረጡ ወይም ያስገቡ: |
| `sale_insufficient_stock` | ⚠️ በቂ ስቶክ የለም! ቀሪ ስቶክ: :stock :unit (ከዜሮ በታች ሽያጭ አልተፈቀደም)። |
| `sale_cart_title` | 🛒 <b>የአሁኑ ሽያጭ ቅርጫት</b><br>:items<br>──────────────────<br>💰 <b>ጠቅላላ ዋጋ: :total ብር</b> |
| `btn_add_more` | ➕ ተጨማሪ ዕቃ ጨምር |
| `btn_finish_sale` | ✅ ሽያጭ ጨርስ (:total ብር) |
| `sale_completed` | ✅ <b>ሽያጩ በተሳካ ሁኔታ ተጠናቋል!</b><br>:items<br>──────────────────<br>💰 <b>የተሰበሰበ ጠቅላላ: :total ብር</b> |
| `btn_next_sale` | 🛒 ቀጣይ ሽያጭ |
| `btn_undo` | ↩️ መልስ (Undo) |
| `adjust_ask_qty` | 🔧 <b>ስቶክ ማስተካከያ: :product</b><br>በመዝገብ ላይ ያለ ስቶክ: <b>:stock :unit</b><br><br>በቆጠራ የተገኘውን ትክክለኛ የዕቃ ብዛት ያስገቡ: |
| `adjust_ask_reason` | 📝 የስቶክ ማስተካከያ ምክንያቱን ይምረጡ: |
| `adjust_reason_damaged` | 💔 የተበላሸ |
| `adjust_reason_lost` | 🔍 የጠፋ / የተሰረቀ |
| `adjust_reason_count` | 📝 የቆጠራ ስህተት |
| `adjust_reason_other` | ⚙️ ሌላ ምክንያት |
| `adjust_success` | ✅ <b>ስቶክ ተስተካክሏል!</b><br>📦 ዕቃ: <b>:product</b><br>🔧 ልዩነት: <b>:diff :unit</b><br>📝 ምክንያት: :reason<br>📊 አዲስ ስቶክ: <b>:new_stock :unit</b> |
| `undo_no_action` | ⚠️ የሚቀለበስ ምንም የቅርብ ጊዜ ተግባር አልተገኘም። |
| `undo_already_done` | ⚠️ ይህ ተግባር አስቀድሞ ተመልሷል። |
| `undo_too_old` | ❌ ከ10 ደቂቃ በላይ ያለፈባቸውን ተግባራት መመለስ አይቻልም። |
| `undo_success` | ↩️ <b>ተግባሩ ተቀልብሷል!</b><br>ለ <b>:product</b> የተመዘገበው :type ተቀልብሷል (:qty :unit)።<br>📊 የአሁኑ ስቶክ: <b>:new_stock :unit</b> |
| `delete_confirm` | ⚠️ <b>:product</b> የተባለውን ዕቃ ለመሰረዝ እርግጠኛ ነዎት?<br>የቀደመ የሽያጭ እና ስቶክ መዝገብ አይሰረዝም። |
| `btn_confirm_delete` | 🗑 አዎ ሰርዝ |
| `delete_success` | 🗑 <b>:product</b> በተሳካ ሁኔታ ተሰርዟል። |
| `search_no_results` | 🔍 ለ "<b>:query</b>" የተገኘ ዕቃ የለም። |
| `menu_transfer` | 🚚 ማስተላለፍ |
| `menu_review_queue` | 📥 ማረጋገጫ የሚጠብቁ |
| `transfer_title` | 🚚 የዕቃ ዝውውር |
| `transfer_success` | ✅ ዝውውሩ በተሳካ ሁኔታ ተጠናቋል! |
| `transfer_discrepancy_alert` | ⚠️ የዝውውር ልዩነት ማስጠንቀቂያ! |
| `location_switched` | 📍 ንቁ የስራ ቦታ ወደ <b>:location</b> ተቀይሯል |
| `btn_receive` | ✅ ተቀበል |
| `btn_different_qty` | ✏️ የተለየ ብዛት |
| `review_queue_empty` | ማረጋገጫ የሚጠብቅ ውድቅ የተደረገ የኦፍላይን ለውጥ የለም። |
| `locations_settings_title` | 📍 የቦታዎች እና ዝውውር አስተዳደር |
