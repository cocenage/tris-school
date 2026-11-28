# Pulse UI — first integration

Vue 3 island on the existing /home Livewire page. Uses the current Vite app entry and shared TRIS tokens. Existing global shell, search header and instruction links remain intact for the independent shell redesign; no fake planner data or planner filtering. Date and text use Rome timezone, the decorative red dot is not bound to Pulse state. Icons, typography, and spacing are approximate until Figma Starter MCP access is restored. No Figma assets were obtained.

Fetches authenticated /focus/pulse, refreshes at most every 30 seconds, refreshes on visibility/online, clears on explicit 401/403, keeps stale data in component memory only and disables stale links. Vue escapes text and accepts same-origin relative navigation only. No localStorage, no cross-page snapshot. Initial load is static skeleton; changed fragments fade, reduced motion supported. On Livewire navigation the island unmounts and refetches.

Verification: npm run build; open /home as approved user, check data, navigation, denied role preview, offline/online, midnight, and Livewire navigation. Build and runtime visual verification required.