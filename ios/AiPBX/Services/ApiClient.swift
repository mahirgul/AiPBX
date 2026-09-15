import Foundation

public enum ApiError: LocalizedError {
    case invalidUrl
    case serverError(statusCode: Int, message: String)
    case decodingError(Error)
    case networkError(Error)
    case custom(String)

    public var errorDescription: String? {
        switch self {
        case .invalidUrl: return "Geçersiz sunucu adresi."
        case .serverError(let code, let msg): return "Sunucu hatası (\(code)): \(msg)"
        case .decodingError(let err): return "Veri işleme hatası: \(err.localizedDescription)"
        case .networkError(let err): return "Ağ bağlantı hatası: \(err.localizedDescription)"
        case .custom(let msg): return msg
        }
    }
}

public final class ApiClient {
    public static let shared = ApiClient()

    private let session: URLSession

    private init() {
        let config = URLSessionConfiguration.default
        config.timeoutIntervalForRequest = 15.0
        config.timeoutIntervalForResource = 30.0
        self.session = URLSession(configuration: config)
    }

    private func cleanUrl(_ baseUrl: String) -> String {
        return baseUrl.trimmingCharacters(in: .whitespacesAndNewlines).trimmingCharacters(in: CharacterSet(charactersIn: "/"))
    }

    // MARK: - Server Ping & Login

    public func ping(baseUrl: String) async throws -> ServerInfo {
        let base = cleanUrl(baseUrl)
        guard let url = URL(string: "\(base)/api/mobile/ping.php") else {
            throw ApiError.invalidUrl
        }

        var request = URLRequest(url: url)
        request.httpMethod = "GET"

        let (data, response) = try await session.data(for: request)
        guard let httpResponse = response as? HTTPURLResponse, (200...299).contains(httpResponse.statusCode) else {
            throw ApiError.serverError(statusCode: (response as? HTTPURLResponse)?.statusCode ?? 500, message: "Sunucuya erişilemedi")
        }

        do {
            return try JSONDecoder().decode(ServerInfo.self, from: data)
        } catch {
            throw ApiError.decodingError(error)
        }
    }

    public func login(baseUrl: String, userOrExt: String, pass: String) async throws -> LoginResponse {
        let base = cleanUrl(baseUrl)
        guard let url = URL(string: "\(base)/api/mobile/login.php") else {
            throw ApiError.invalidUrl
        }

        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.setValue("application/json; charset=utf-8", forHTTPHeaderField: "Content-Type")

        let body: [String: Any] = [
            "username": userOrExt,
            "password": pass,
            "platform": "ios"
        ]
        request.httpBody = try JSONSerialization.data(withJSONObject: body)

        let (data, response) = try await session.data(for: request)
        guard let httpResponse = response as? HTTPURLResponse else {
            throw ApiError.custom("Sunucu yanıtı alınamadı")
        }

        do {
            let res = try JSONDecoder().decode(LoginResponse.self, from: data)
            if !res.success {
                throw ApiError.custom(res.error ?? "Giriş başarısız")
            }
            return res
        } catch {
            if httpResponse.statusCode == 401 {
                throw ApiError.custom("Kullanıcı adı veya şifre hatalı")
            }
            throw ApiError.decodingError(error)
        }
    }

    // MARK: - Call History

    public func getCallHistory(baseUrl: String, token: String, filter: String = "all", limit: Int = 100, offset: Int = 0) async throws -> CallHistoryResponse {
        let base = cleanUrl(baseUrl)
        guard let url = URL(string: "\(base)/api/mobile/history.php?filter=\(filter)&limit=\(limit)&offset=\(offset)") else {
            throw ApiError.invalidUrl
        }

        var request = URLRequest(url: url)
        request.httpMethod = "GET"
        request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")

        let (data, response) = try await session.data(for: request)
        guard let httpResponse = response as? HTTPURLResponse, (200...299).contains(httpResponse.statusCode) else {
            throw ApiError.serverError(statusCode: (response as? HTTPURLResponse)?.statusCode ?? 500, message: "Geçmiş yüklenemedi")
        }

        return try JSONDecoder().decode(CallHistoryResponse.self, from: data)
    }

    // MARK: - Contacts

    public func getContacts(baseUrl: String, token: String) async throws -> [ContactItem] {
        let base = cleanUrl(baseUrl)
        guard let url = URL(string: "\(base)/api/mobile/contacts.php") else {
            throw ApiError.invalidUrl
        }

        var request = URLRequest(url: url)
        request.httpMethod = "GET"
        request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")

        let (data, response) = try await session.data(for: request)
        guard let httpResponse = response as? HTTPURLResponse, (200...299).contains(httpResponse.statusCode) else {
            throw ApiError.serverError(statusCode: (response as? HTTPURLResponse)?.statusCode ?? 500, message: "Rehber yüklenemedi")
        }

        let res = try JSONDecoder().decode(ContactsResponse.self, from: data)
        return res.contacts ?? []
    }

    // MARK: - Features / DND / Forwarding

    public func getFeatures(baseUrl: String, token: String) async throws -> FeatureSettings {
        let base = cleanUrl(baseUrl)
        guard let url = URL(string: "\(base)/api/mobile/features.php") else {
            throw ApiError.invalidUrl
        }

        var request = URLRequest(url: url)
        request.httpMethod = "GET"
        request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")

        let (data, response) = try await session.data(for: request)
        guard let httpResponse = response as? HTTPURLResponse, (200...299).contains(httpResponse.statusCode) else {
            throw ApiError.serverError(statusCode: (response as? HTTPURLResponse)?.statusCode ?? 500, message: "Ayarlar alınamadı")
        }

        let res = try JSONDecoder().decode(FeaturesResponse.self, from: data)
        if let features = res.features {
            return features
        }
        throw ApiError.custom(res.message ?? "Santral ayarları okunamadı")
    }

    public func updateFeatures(baseUrl: String, token: String, settings: FeatureSettings) async throws -> Bool {
        let base = cleanUrl(baseUrl)
        guard let url = URL(string: "\(base)/api/mobile/features.php") else {
            throw ApiError.invalidUrl
        }

        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        request.setValue("application/json; charset=utf-8", forHTTPHeaderField: "Content-Type")

        let body: [String: Any] = [
            "dnd_enabled": settings.dndEnabled,
            "call_forward_number": settings.callForwardNumber ?? "",
            "cf_busy_number": settings.cfBusyNumber ?? "",
            "cf_noanswer_number": settings.cfNoAnswerNumber ?? "",
            "cf_noanswer_timeout": settings.cfNoAnswerTimeout ?? 20
        ]
        request.httpBody = try JSONSerialization.data(withJSONObject: body)

        let (data, response) = try await session.data(for: request)
        guard let httpResponse = response as? HTTPURLResponse, (200...299).contains(httpResponse.statusCode) else {
            throw ApiError.serverError(statusCode: (response as? HTTPURLResponse)?.statusCode ?? 500, message: "Güncelleme başarısız")
        }

        let res = try JSONDecoder().decode(FeaturesResponse.self, from: data)
        return res.success
    }

    // MARK: - Chat APIs

    public func getChatConversations(baseUrl: String, token: String) async throws -> [ChatConversation] {
        let base = cleanUrl(baseUrl)
        guard let url = URL(string: "\(base)/chat/api/conversations") else {
            throw ApiError.invalidUrl
        }

        var request = URLRequest(url: url)
        request.httpMethod = "GET"
        request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")

        let (data, response) = try await session.data(for: request)
        guard let httpResponse = response as? HTTPURLResponse, (200...299).contains(httpResponse.statusCode) else {
            throw ApiError.serverError(statusCode: (response as? HTTPURLResponse)?.statusCode ?? 500, message: "Sohbetler alınamadı")
        }

        let res = try JSONDecoder().decode(ChatConversationsResponse.self, from: data)
        return res.conversations ?? []
    }

    public func getChatMessages(baseUrl: String, token: String, convId: Int, limit: Int = 50, beforeId: Int64 = 0) async throws -> [ChatMessage] {
        let base = cleanUrl(baseUrl)
        var urlStr = "\(base)/chat/api/messages?conversation_id=\(convId)&limit=\(limit)"
        if beforeId > 0 {
            urlStr += "&before_id=\(beforeId)"
        }
        guard let url = URL(string: urlStr) else {
            throw ApiError.invalidUrl
        }

        var request = URLRequest(url: url)
        request.httpMethod = "GET"
        request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")

        let (data, response) = try await session.data(for: request)
        guard let httpResponse = response as? HTTPURLResponse, (200...299).contains(httpResponse.statusCode) else {
            throw ApiError.serverError(statusCode: (response as? HTTPURLResponse)?.statusCode ?? 500, message: "Mesajlar alınamadı")
        }

        let res = try JSONDecoder().decode(ChatMessagesResponse.self, from: data)
        return res.messages ?? []
    }

    public func createDirectChat(baseUrl: String, token: String, targetExt: String) async throws -> ChatConversation {
        let base = cleanUrl(baseUrl)
        guard let url = URL(string: "\(base)/chat/api/direct") else {
            throw ApiError.invalidUrl
        }

        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        request.setValue("application/json; charset=utf-8", forHTTPHeaderField: "Content-Type")

        let body = ["target_ext": targetExt]
        request.httpBody = try JSONSerialization.data(withJSONObject: body)

        let (data, response) = try await session.data(for: request)
        guard let httpResponse = response as? HTTPURLResponse, (200...299).contains(httpResponse.statusCode) else {
            throw ApiError.serverError(statusCode: (response as? HTTPURLResponse)?.statusCode ?? 500, message: "Sohbet oluşturulamadı")
        }

        let res = try JSONDecoder().decode(DirectChatResponse.self, from: data)
        if let conv = res.conversation {
            return conv
        }
        throw ApiError.custom(res.error ?? "Doğrudan sohbet açılamadı")
    }

    public func createGroupChat(baseUrl: String, token: String, title: String, members: [String], description: String = "") async throws -> ChatConversation {
        let base = cleanUrl(baseUrl)
        guard let url = URL(string: "\(base)/chat/api/group") else {
            throw ApiError.invalidUrl
        }

        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        request.setValue("application/json; charset=utf-8", forHTTPHeaderField: "Content-Type")

        let body: [String: Any] = [
            "title": title,
            "description": description,
            "members": members
        ]
        request.httpBody = try JSONSerialization.data(withJSONObject: body)

        let (data, response) = try await session.data(for: request)
        guard let httpResponse = response as? HTTPURLResponse, (200...299).contains(httpResponse.statusCode) else {
            throw ApiError.serverError(statusCode: (response as? HTTPURLResponse)?.statusCode ?? 500, message: "Grup oluşturulamadı")
        }

        let res = try JSONDecoder().decode(GroupChatResponse.self, from: data)
        if let conv = res.conversation {
            return conv
        }
        throw ApiError.custom(res.error ?? "Grup sohbeti oluşturulamadı")
    }

    public func leaveGroup(baseUrl: String, token: String, convId: Int) async throws -> Bool {
        let base = cleanUrl(baseUrl)
        guard let url = URL(string: "\(base)/chat/api/group/leave") else {
            throw ApiError.invalidUrl
        }

        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        request.setValue("application/json; charset=utf-8", forHTTPHeaderField: "Content-Type")

        let body = ["conversation_id": convId]
        request.httpBody = try JSONSerialization.data(withJSONObject: body)

        let (data, response) = try await session.data(for: request)
        guard let httpResponse = response as? HTTPURLResponse, (200...299).contains(httpResponse.statusCode) else {
            throw ApiError.serverError(statusCode: (response as? HTTPURLResponse)?.statusCode ?? 500, message: "Gruptan çıkılamadı")
        }

        let res = try JSONDecoder().decode(GenericActionResponse.self, from: data)
        return res.success
    }
}
