import React, { createContext, useContext, useMemo, useState } from 'react';

/**
 * Whether the conversation list currently shows unread conversations — drives the dot on the Conversations tab.
 * The mobile API has no unread COUNT yet, so the tab shows a dot rather than a number that could be wrong (only the
 * loaded pages are known on the phone).
 */
type UnreadState = { hasUnread: boolean; setHasUnread: (v: boolean) => void };

const Ctx = createContext<UnreadState>({ hasUnread: false, setHasUnread: () => undefined });

export function UnreadProvider({ children }: { children: React.ReactNode }) {
  const [hasUnread, setHasUnread] = useState(false);
  const value = useMemo(() => ({ hasUnread, setHasUnread }), [hasUnread]);
  return <Ctx.Provider value={value}>{children}</Ctx.Provider>;
}

export const useUnread = () => useContext(Ctx);
