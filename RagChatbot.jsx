/**
 * RagChatbot.jsx
 *
 * Drop-in replacement for RasaChatbot.jsx
 * Connects to the Laravel RAG backend instead of Rasa.
 *
 * Changes from RasaChatbot.jsx:
 *   - VITE_RASA_WEBHOOK_URL  →  VITE_CHATBOT_URL (your Laravel API base URL)
 *   - Health check: /healthz  →  /api/health
 *   - Chat endpoint: /webhooks/rest/webhook  →  /api/chat
 *   - Request body now includes "locale" for bilingual support
 *   - Conversation history is passed for multi-turn context
 *
 * Update your .env:
 *   VITE_CHATBOT_URL=http://localhost:8000   (local dev)
 *   VITE_CHATBOT_URL=https://api.yourdomain.com  (production)
 */

import { useState, useEffect, useRef } from "react";
import { motion, AnimatePresence } from "motion/react";
import {
  MessageSquare,
  X,
  Send,
  RotateCcw,
  Volume2,
  VolumeX,
  Mic,
  Wifi,
  WifiOff,
  Sparkles,
} from "lucide-react";
import { useLanguage } from "../context/LanguageContext";

export function RagChatbot() {
  const { locale } = useLanguage();

  const [isOpen, setIsOpen] = useState(false);
  const [messages, setMessages] = useState([]);
  const [inputText, setInputText] = useState("");
  const [isLoading, setIsLoading] = useState(false);
  const [isMuted, setIsMuted] = useState(false);
  const [isListening, setIsListening] = useState(false);

  // "connected" | "offline" | "unknown"
  const [serverStatus, setServerStatus] = useState("unknown");

  // Base URL for the Laravel RAG backend
  const apiBase =
    import.meta.env.VITE_CHATBOT_URL ||
    "http://localhost:8000";

  // Stable session ID for this browser session
  const [senderId] = useState(
    () => "user_" + Math.random().toString(36).substring(2, 11),
  );

  // Keep last 10 turns for multi-turn context sent to Gemini
  const conversationHistory = useRef([]);

  const messagesEndRef = useRef(null);
  const recognitionRef = useRef(null);

  // ─── Timestamps ────────────────────────────────────────────────────────────
  const getTimestamp = () =>
    new Date().toLocaleTimeString(locale === "ar" ? "ar-EG" : "en-US", {
      hour: "2-digit",
      minute: "2-digit",
    });

  // ─── Welcome Message ────────────────────────────────────────────────────────
  const getWelcomeMessage = () => {
    const welcomeEn =
      "Hello! I'm **GEDULink's Smart Academic Assistant** 🎓\n\nI can help you with:\n\n• Available universities & countries 🌍\n• Bachelor, Master & PhD programs 📚\n• Tuition fees & admission requirements 💰\n• Online vs Onsite study options 💻\n• Contact our support team 📞\n\n*Ask me anything!*";

    const welcomeAr =
      "مرحباً! أنا **مستشارك الأكاديمي الذكي من GEDULink** 🎓\n\nيسعدني مساعدتك في:\n\n• الجامعات المتاحة والدول 🌍\n• برامج البكالوريوس والماجستير والدكتوراه 📚\n• الرسوم الدراسية وشروط القبول 💰\n• الدراسة أونلاين أو حضورياً 💻\n• التواصل مع فريق الدعم 📞\n\n*اسألني أي شيء!*";

    return locale === "ar" ? welcomeAr : welcomeEn;
  };

  // ─── Quick Suggestions ──────────────────────────────────────────────────────
  const getSuggestions = () => {
    if (locale === "ar") {
      return [
        "ما هي الجامعات المتاحة؟",
        "أي برامج MBA متوفرة أونلاين؟",
        "ما هي رسوم الدراسة في المملكة المتحدة؟",
        "كيف أتواصل مع فريق القبول؟",
        "هل يمكنني الدراسة عن بعد؟",
      ];
    }
    return [
      "What universities are available?",
      "Which MBA programs are online?",
      "What are UK tuition fees?",
      "How do I contact admissions?",
      "Can I study remotely?",
    ];
  };

  // ─── Scroll ─────────────────────────────────────────────────────────────────
  const scrollToBottom = () => {
    messagesEndRef.current?.scrollIntoView({ behavior: "smooth" });
  };

  useEffect(() => {
    if (isOpen) setTimeout(scrollToBottom, 100);
  }, [messages, isOpen]);

  // ─── Init on locale change ──────────────────────────────────────────────────
  useEffect(() => {
    conversationHistory.current = [];
    setMessages([
      {
        id: "welcome",
        sender: "bot",
        text: getWelcomeMessage(),
        timestamp: getTimestamp(),
      },
    ]);
    checkHealth();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [locale]);

  // ─── Health Check ────────────────────────────────────────────────────────────
  const checkHealth = async () => {
    try {
      const res = await fetch(`${apiBase}/api/health`, {
        method: "GET",
        signal: AbortSignal.timeout(8000),
      });
      if (res.ok) {
        const data = await res.json();
        setServerStatus(data.status === "ok" ? "connected" : "offline");
      } else {
        setServerStatus("offline");
      }
    } catch {
      // Network error — mark as unknown rather than offline (could be cold start)
      setServerStatus("unknown");
    }
  };

  // ─── Send Message ────────────────────────────────────────────────────────────
  const handleSend = async (textOverride) => {
    const text = (textOverride ?? inputText).trim();
    if (!text || isLoading) return;

    setInputText("");
    setIsLoading(true);

    // Add user message to UI
    const userMsg = {
      id: `user_${Date.now()}`,
      sender: "user",
      text,
      timestamp: getTimestamp(),
    };
    setMessages((prev) => [...prev, userMsg]);

    // Add to history for multi-turn context (keep last 10)
    conversationHistory.current.push({ role: "user", text });
    if (conversationHistory.current.length > 20) {
      conversationHistory.current = conversationHistory.current.slice(-20);
    }

    try {
      const response = await fetch(`${apiBase}/api/chat`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          sender: senderId,
          message: text,
          locale: locale || "ar",
          history: conversationHistory.current.slice(0, -1), // exclude current turn
        }),
        signal: AbortSignal.timeout(30000),
      });

      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }

      const data = await response.json();
      const replies = data.replies ?? [];

      if (replies.length === 0) {
        throw new Error("No reply received");
      }

      const botText = replies.map((r) => r.text).join("\n\n");

      const botMsg = {
        id: `bot_${Date.now()}`,
        sender: "bot",
        text: botText,
        timestamp: getTimestamp(),
      };
      setMessages((prev) => [...prev, botMsg]);

      // Add bot reply to history
      conversationHistory.current.push({ role: "model", text: botText });

      setServerStatus("connected");

    } catch (err) {
      const isAr = locale === "ar";
      const errorText = isAr
        ? `❌ عذراً، حدث خطأ في الاتصال بالخادم.\n\n*${err.message}*\n\nيرجى المحاولة مرة أخرى.`
        : `❌ Sorry, failed to connect to the server.\n\n*${err.message}*\n\nPlease try again.`;

      setMessages((prev) => [
        ...prev,
        { id: `err_${Date.now()}`, sender: "error", text: errorText, timestamp: getTimestamp() },
      ]);
      setServerStatus("offline");

    } finally {
      setIsLoading(false);
    }
  };

  // ─── Reset ───────────────────────────────────────────────────────────────────
  const handleReset = () => {
    conversationHistory.current = [];
    setMessages([
      {
        id: "welcome_reset",
        sender: "bot",
        text: getWelcomeMessage(),
        timestamp: getTimestamp(),
      },
    ]);
  };

  // ─── Voice Input ─────────────────────────────────────────────────────────────
  const handleVoice = () => {
    if (!("webkitSpeechRecognition" in window || "SpeechRecognition" in window)) {
      alert("Voice not supported in your browser.");
      return;
    }
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (isListening) {
      recognitionRef.current?.stop();
      setIsListening(false);
      return;
    }
    const recognition = new SR();
    recognition.lang = locale === "ar" ? "ar-AE" : "en-US";
    recognition.interimResults = false;
    recognition.onresult = (e) => {
      const transcript = e.results[0][0].transcript;
      setInputText(transcript);
      setIsListening(false);
    };
    recognition.onerror = () => setIsListening(false);
    recognition.onend = () => setIsListening(false);
    recognitionRef.current = recognition;
    recognition.start();
    setIsListening(true);
  };

  // ─── Markdown renderer (simple) ──────────────────────────────────────────────
  const renderMarkdown = (text) => {
    const lines = text.split("\n");
    return lines.map((line, i) => {
      // Bold **text**
      line = line.replace(/\*\*(.*?)\*\*/g, "<strong>$1</strong>");
      // Italic *text*
      line = line.replace(/\*(.*?)\*/g, "<em>$1</em>");
      // Bullet
      if (line.startsWith("• ") || line.startsWith("- ")) {
        return (
          <li key={i} className="ml-4 list-disc text-sm" dangerouslySetInnerHTML={{ __html: line.slice(2) }} />
        );
      }
      if (line.trim() === "") return <br key={i} />;
      return (
        <p key={i} className="text-sm leading-relaxed" dangerouslySetInnerHTML={{ __html: line }} />
      );
    });
  };

  // ─── Status Indicator ────────────────────────────────────────────────────────
  const statusColor = {
    connected: "bg-green-500",
    offline: "bg-red-500",
    unknown: "bg-yellow-400",
  }[serverStatus] ?? "bg-yellow-400";

  // ─── Render ──────────────────────────────────────────────────────────────────
  return (
    <>
      {/* Chat Toggle Button */}
      <button
        onClick={() => setIsOpen((v) => !v)}
        className="fixed bottom-6 right-6 z-50 w-14 h-14 rounded-full bg-blue-600 hover:bg-blue-700 text-white shadow-xl flex items-center justify-center transition-all"
        aria-label="Open chatbot"
      >
        {isOpen ? <X size={22} /> : <MessageSquare size={22} />}
        {/* Status dot */}
        <span className={`absolute top-1 right-1 w-3 h-3 rounded-full border-2 border-white ${statusColor}`} />
      </button>

      {/* Chat Window */}
      <AnimatePresence>
        {isOpen && (
          <motion.div
            initial={{ opacity: 0, y: 20, scale: 0.95 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            exit={{ opacity: 0, y: 20, scale: 0.95 }}
            transition={{ duration: 0.2 }}
            className="fixed bottom-24 right-6 z-50 w-[360px] max-w-[95vw] h-[520px] bg-white rounded-2xl shadow-2xl flex flex-col overflow-hidden border border-gray-200"
            dir={locale === "ar" ? "rtl" : "ltr"}
          >
            {/* Header */}
            <div className="bg-gradient-to-r from-blue-600 to-blue-700 px-4 py-3 flex items-center gap-3">
              <div className="w-8 h-8 rounded-full bg-white/20 flex items-center justify-center">
                <Sparkles size={16} className="text-white" />
              </div>
              <div className="flex-1">
                <p className="text-white font-semibold text-sm">
                  {locale === "ar" ? "المستشار الأكاديمي" : "Academic Assistant"}
                </p>
                <div className="flex items-center gap-1">
                  {serverStatus === "connected" ? (
                    <Wifi size={10} className="text-green-300" />
                  ) : (
                    <WifiOff size={10} className="text-yellow-300" />
                  )}
                  <span className="text-white/70 text-xs">
                    {serverStatus === "connected"
                      ? (locale === "ar" ? "متصل • RAG" : "Connected • RAG")
                      : (locale === "ar" ? "جاري الاتصال..." : "Connecting...")}
                  </span>
                </div>
              </div>
              <button onClick={handleReset} className="text-white/70 hover:text-white transition-colors">
                <RotateCcw size={15} />
              </button>
              <button onClick={() => setIsMuted((v) => !v)} className="text-white/70 hover:text-white transition-colors">
                {isMuted ? <VolumeX size={15} /> : <Volume2 size={15} />}
              </button>
            </div>

            {/* Messages */}
            <div className="flex-1 overflow-y-auto px-3 py-3 space-y-3 bg-gray-50">
              {messages.map((msg) => {
                const isUser  = msg.sender === "user";
                const isError = msg.sender === "error";
                return (
                  <div key={msg.id} className={`flex ${isUser ? "justify-end" : "justify-start"}`}>
                    <div
                      className={`max-w-[80%] rounded-2xl px-3 py-2 text-sm shadow-sm ${
                        isUser
                          ? "bg-blue-600 text-white rounded-br-sm"
                          : isError
                          ? "bg-red-50 border border-red-200 text-red-700 rounded-bl-sm"
                          : "bg-white text-gray-800 rounded-bl-sm border border-gray-100"
                      }`}
                    >
                      <div className="space-y-0.5">{renderMarkdown(msg.text)}</div>
                      <p className={`text-[10px] mt-1 ${isUser ? "text-blue-200" : "text-gray-400"}`}>
                        {msg.timestamp}
                      </p>
                    </div>
                  </div>
                );
              })}

              {/* Loading indicator */}
              {isLoading && (
                <div className="flex justify-start">
                  <div className="bg-white rounded-2xl rounded-bl-sm px-3 py-2 border border-gray-100 shadow-sm">
                    <div className="flex gap-1 items-center h-5">
                      {[0, 1, 2].map((i) => (
                        <span
                          key={i}
                          className="w-2 h-2 rounded-full bg-blue-400 animate-bounce"
                          style={{ animationDelay: `${i * 0.15}s` }}
                        />
                      ))}
                    </div>
                  </div>
                </div>
              )}

              {/* Quick suggestions (shown when few messages) */}
              {!isLoading && messages.length <= 2 && (
                <div className="space-y-1 pt-1">
                  <p className="text-xs text-gray-400 text-center">
                    {locale === "ar" ? "اقتراحات سريعة" : "Quick suggestions"}
                  </p>
                  {getSuggestions().map((s) => (
                    <button
                      key={s}
                      onClick={() => handleSend(s)}
                      className="w-full text-left text-xs bg-white border border-blue-100 hover:border-blue-300 hover:bg-blue-50 text-blue-700 rounded-xl px-3 py-1.5 transition-all"
                    >
                      {s}
                    </button>
                  ))}
                </div>
              )}

              <div ref={messagesEndRef} />
            </div>

            {/* Input */}
            <div className="px-3 py-2 bg-white border-t border-gray-100">
              <div className="flex items-center gap-2 bg-gray-50 rounded-xl px-3 py-2">
                <input
                  type="text"
                  value={inputText}
                  onChange={(e) => setInputText(e.target.value)}
                  onKeyDown={(e) => e.key === "Enter" && !e.shiftKey && handleSend()}
                  placeholder={locale === "ar" ? "اكتب سؤالك..." : "Ask anything..."}
                  className="flex-1 bg-transparent text-sm text-gray-800 placeholder-gray-400 outline-none"
                  disabled={isLoading}
                />
                <button
                  onClick={handleVoice}
                  className={`text-gray-400 hover:text-blue-500 transition-colors ${isListening ? "text-red-500 animate-pulse" : ""}`}
                >
                  <Mic size={16} />
                </button>
                <button
                  onClick={() => handleSend()}
                  disabled={!inputText.trim() || isLoading}
                  className="w-8 h-8 bg-blue-600 disabled:bg-gray-300 hover:bg-blue-700 text-white rounded-lg flex items-center justify-center transition-colors"
                >
                  <Send size={14} />
                </button>
              </div>
              <p className="text-center text-[10px] text-gray-300 mt-1">
                Powered by GEDULink RAG • Gemini + Qdrant
              </p>
            </div>
          </motion.div>
        )}
      </AnimatePresence>
    </>
  );
}
