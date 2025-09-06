<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ImmoLink - Plateforme Immobilière</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --secondary: #64748b;
            --light: #f8fafc;
            --dark: #1e293b;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --gray: #94a3b8;
            --gray-light: #e2e8f0;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f1f5f9;
            color: var(--dark);
            line-height: 1.6;
        }

        .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        /* Header Styles */
        header {
            background-color: white;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .navbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
        }

        .logo {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
            text-decoration: none;
            display: flex;
            align-items: center;
        }

        .logo i {
            margin-right: 10px;
        }

        .nav-links {
            display: flex;
            gap: 25px;
        }

        .nav-links a {
            text-decoration: none;
            color: var(--secondary);
            font-weight: 500;
            transition: color 0.3s;
            position: relative;
        }

        .nav-links a:hover {
            color: var(--primary);
        }

        .nav-links a::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 0;
            width: 0;
            height: 2px;
            background-color: var(--primary);
            transition: width 0.3s;
        }

        .nav-links a:hover::after {
            width: 100%;
        }

        .auth-buttons {
            display: flex;
            gap: 15px;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }

        .btn-primary {
            background-color: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .btn-outline {
            background-color: transparent;
            border: 1px solid var(--primary);
            color: var(--primary);
        }

        .btn-outline:hover {
            background-color: var(--primary);
            color: white;
        }

        /* Hero Section */
        .hero {
            padding: 100px 0;
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: white;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="none"><path d="M0,0 L100,0 L100,100 Z" fill="rgba(255,255,255,0.05)"/></svg>');
            background-size: 100% 100%;
        }

        .hero h1 {
            font-size: 3.5rem;
            margin-bottom: 20px;
            font-weight: 700;
        }

        .hero p {
            font-size: 1.25rem;
            max-width: 700px;
            margin: 0 auto 40px;
            opacity: 0.9;
        }

        /* Advanced Search */
        .advanced-search {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
        }

        .search-tabs {
            display: flex;
            background: var(--light);
        }

        .search-tab {
            flex: 1;
            padding: 15px;
            text-align: center;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .search-tab.active {
            background-color: white;
            font-weight: 600;
            color: var(--primary);
        }

        .search-form {
            padding: 25px;
        }

        .search-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }

        .search-input {
            padding: 12px 15px;
            border: 1px solid var(--gray-light);
            border-radius: 6px;
            font-size: 16px;
            width: 100%;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .search-button {
            padding: 15px 30px;
            background-color: var(--primary);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            transition: background-color 0.3s;
            width: 100%;
        }

        .search-button:hover {
            background-color: var(--primary-dark);
        }

        /* Properties Section */
        .section-title {
            text-align: center;
            margin: 80px 0 40px;
            font-size: 2.5rem;
            color: var(--dark);
            position: relative;
        }

        .section-title::after {
            content: '';
            display: block;
            width: 80px;
            height: 4px;
            background-color: var(--primary);
            margin: 15px auto 0;
            border-radius: 2px;
        }

        .properties-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 30px;
            margin-bottom: 80px;
        }

        .property-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            transition: transform 0.3s, box-shadow 0.3s;
            position: relative;
        }

        .property-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15);
        }

        .property-badge {
            position: absolute;
            top: 15px;
            left: 15px;
            background-color: var(--primary);
            color: white;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            z-index: 2;
        }

        .property-image {
            height: 220px;
            width: 100%;
            object-fit: cover;
            transition: transform 0.3s;
        }

        .property-card:hover .property-image {
            transform: scale(1.05);
        }

        .property-details {
            padding: 25px;
        }

        .property-price {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 10px;
        }

        .property-title {
            font-size: 1.25rem;
            margin-bottom: 10px;
            color: var(--dark);
        }

        .property-address {
            color: var(--secondary);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
        }

        .property-address i {
            margin-right: 8px;
        }

        .property-features {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            color: var(--secondary);
            flex-wrap: wrap;
        }

        .property-features span {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .property-actions {
            display: flex;
            gap: 10px;
        }

        /* How it Works Section */
        .how-it-works {
            background-color: white;
            padding: 80px 0;
        }

        .steps {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 40px;
            margin-top: 50px;
        }

        .step {
            text-align: center;
            padding: 30px;
            border-radius: 12px;
            background-color: var(--light);
            transition: transform 0.3s;
            position: relative;
        }

        .step-number {
            position: absolute;
            top: -15px;
            left: 50%;
            transform: translateX(-50%);
            background-color: var(--primary);
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 18px;
        }

        .step:hover {
            transform: translateY(-5px);
        }

        .step-icon {
            font-size: 3rem;
            color: var(--primary);
            margin-bottom: 20px;
            margin-top: 15px;
        }

        .step h3 {
            margin-bottom: 15px;
            color: var(--dark);
        }

        /* Stats Section */
        .stats-section {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 80px 0;
            text-align: center;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 30px;
            margin-top: 50px;
        }

        .stat-item {
            padding: 30px;
        }

        .stat-number {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .stat-label {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        /* Footer */
        footer {
            background-color: var(--dark);
            color: white;
            padding: 80px 0 30px;
        }

        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 50px;
            margin-bottom: 50px;
        }

        .footer-column h3 {
            font-size: 1.5rem;
            margin-bottom: 25px;
            position: relative;
            display: inline-block;
        }

        .footer-column h3::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 0;
            width: 40px;
            height: 3px;
            background-color: var(--primary);
        }

        .footer-column ul {
            list-style: none;
        }

        .footer-column ul li {
            margin-bottom: 12px;
        }

        .footer-column a {
            color: var(--gray);
            text-decoration: none;
            transition: color 0.3s;
            display: inline-block;
        }

        .footer-column a:hover {
            color: white;
            transform: translateX(5px);
        }

        .social-links {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }

        .social-links a {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            background-color: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            transition: all 0.3s;
        }

        .social-links a:hover {
            background-color: var(--primary);
            transform: translateY(-3px);
        }

        .copyright {
            text-align: center;
            padding-top: 30px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            color: var(--gray);
        }

        /* Mobile Navigation */
        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--dark);
        }

        /* Responsive Design */
        @media (max-width: 992px) {
            .navbar {
                flex-direction: column;
                gap: 20px;
            }

            .nav-links {
                gap: 15px;
                flex-wrap: wrap;
                justify-content: center;
            }

            .hero h1 {
                font-size: 2.5rem;
            }

            .hero p {
                font-size: 1.1rem;
            }

            .section-title {
                font-size: 2rem;
            }
        }

        @media (max-width: 768px) {
            .mobile-menu-btn {
                display: block;
            }

            .nav-links {
                display: none;
                flex-direction: column;
                width: 100%;
                text-align: center;
            }

            .nav-links.active {
                display: flex;
            }

            .search-row {
                grid-template-columns: 1fr;
            }

            .properties-grid {
                grid-template-columns: 1fr;
            }

            .footer-content {
                grid-template-columns: 1fr;
                text-align: center;
            }

            .footer-column h3::after {
                left: 50%;
                transform: translateX(-50%);
            }

            .auth-buttons {
                flex-direction: column;
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <div class="navbar">
                <a href="index.php" class="logo">
                    <i class="fas fa-home"></i>
                    ImmoLink
                </a>
                
                <button class="mobile-menu-btn" id="mobileMenuBtn">
                    <i class="fas fa-bars"></i>
                </button>
                
                <div class="nav-links" id="navLinks">
                    <a href="index.php">Accueil</a>
                    <a href="properties.php?type=vente">Acheter</a>
                    <a href="properties.php?type=location">Louer</a>
                    <a href="contact.php">Contact</a>
                    
                    <div class="auth-buttons">
                        <a href="login.php" class="btn btn-outline">Connexion</a>
                        <a href="register.php" class="btn btn-primary">Inscription</a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <section class="hero">
        <div class="container">
            <h1>Trouvez la maison de vos rêves</h1>
            <p>ImmoLink vous connecte avec les meilleures propriétés pour acheter ou louer selon vos préférences.</p>
            
            <div class="advanced-search">
                <div class="search-tabs">
                    <div class="search-tab active" data-tab="buy">Acheter</div>
                    <div class="search-tab" data-tab="rent">Louer</div>
                </div>
                
                <form class="search-form" id="searchForm">
                    <div class="search-row">
                        <input type="text" class="search-input" placeholder="Ville, adresse ou code postal" id="searchLocation">
                        <select class="search-input" id="propertyType">
                            <option value="">Type de bien</option>
                            <option value="appartement">Appartement</option>
                            <option value="maison">Maison</option>
                            <option value="villa">Villa</option>
                            <option value="commercial">Commercial</option>
                        </select>
                    </div>
                    
                    <div class="search-row">
                        <select class="search-input" id="minPrice">
                            <option value="">Prix min</option>
                            <option value="500000">500 000 Fcfa</option>
                            <option value="1000000">1 000 000 Fcfa</option>
                            <option value="5000000">5 000 000 Fcfa</option>
                            <option value="10000000">10 000 000 Fcfa</option>
                        </select>
                        <select class="search-input" id="maxPrice">
                            <option value="">Prix max</option>
                            <option value="5000000">5 000 000 Fcfa</option>
                            <option value="10000000">10 000 000 Fcfa</option>
                            <option value="20000000">20 000 000 Fcfa</option>
                            <option value="50000000">50 000 000 Fcfa</option>
                        </select>
                        <select class="search-input" id="rooms">
                            <option value="">Pièces</option>
                            <option value="1">1 pièce</option>
                            <option value="2">2 pièces</option>
                            <option value="3">3 pièces</option>
                            <option value="4">4 pièces+</option>
                        </select>
                    </div>
                    
                    <button type="submit" class="search-button">
                        <i class="fas fa-search"></i> Rechercher
                    </button>
                </form>
            </div>
        </div>
    </section>

    <section class="container">
        <h2 class="section-title">Biens en vedette</h2>
        <div class="properties-grid" id="featuredProperties">
            <!-- Properties will be loaded dynamically -->
        </div>
        
        <div style="text-align: center; margin-bottom: 80px;">
            <a href="properties.php" class="btn btn-primary">Voir tous les biens</a>
        </div>
    </section>

    <section class="how-it-works">
        <div class="container">
            <h2 class="section-title">Comment ça marche</h2>
            <div class="steps">
                <div class="step">
                    <div class="step-number">1</div>
                    <div class="step-icon">
                        <i class="fas fa-user-circle"></i>
                    </div>
                    <h3>Créez votre compte</h3>
                    <p>Inscrivez-vous en tant que propriétaire ou locataire selon vos besoins.</p>
                </div>
                <div class="step">
                    <div class="step-number">2</div>
                    <div class="step-icon">
                        <i class="fas fa-home"></i>
                    </div>
                    <h3>Publiez ou explorez</h3>
                    <p>Propriétaires: publiez vos biens. Locataires: trouvez le logement parfait.</p>
                </div>
                <div class="step">
                    <div class="step-number">3</div>
                    <div class="step-icon">
                        <i class="fas fa-comments"></i>
                    </div>
                    <h3>Communiquez</h3>
                    <p>Discutez en temps réel et concluez votre transaction en toute simplicité.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="stats-section">
        <div class="container">
            <h2 class="section-title" style="color: white;">ImmoLink en chiffres</h2>
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-number" id="statProperties">0</div>
                    <div class="stat-label">Biens disponibles</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number" id="statUsers">0</div>
                    <div class="stat-label">Utilisateurs satisfaits</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number" id="statTransactions">0</div>
                    <div class="stat-label">Transactions</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number" id="statCities">0</div>
                    <div class="stat-label">Villes couvertes</div>
                </div>
            </div>
        </div>
    </section>

    <footer>
        <div class="container">
            <div class="footer-content">
                <div class="footer-column">
                    <h3>ImmoLink</h3>
                    <p>La plateforme immobilière moderne pour acheter, vendre et louer des biens en toute simplicité.</p>
                    <div class="social-links">
                        <a href="#"><i class="fab fa-facebook-f"></i></a>
                        <a href="#"><i class="fab fa-twitter"></i></a>
                        <a href="#"><i class="fab fa-instagram"></i></a>
                        <a href="#"><i class="fab fa-linkedin-in"></i></a>
                    </div>
                </div>
                <div class="footer-column">
                    <h3>Liens rapides</h3>
                    <ul>
                        <li><a href="index.php">Accueil</a></li>
                        <li><a href="properties.php?type=vente">Acheter</a></li>
                        <li><a href="properties.php?type=location">Louer</a></li>
                        <li><a href="contact.php">Contact</a></li>
                    </ul>
                </div>
                <div class="footer-column">
                    <h3>Légal</h3>
                    <ul>
                        <li><a href="terms.php">Conditions d'utilisation</a></li>
                        <li><a href="privacy.php">Politique de confidentialité</a></li>
                        <li><a href="legal.php">Mentions légales</a></li>
                    </ul>
                </div>
                <div class="footer-column">
                    <h3>Contact</h3>
                    <ul>
                        <li><i class="fas fa-envelope"></i> contact@immolink.cm</li>
                        <li><i class="fas fa-phone"></i> +237 6 99 71 46 82</li>
                        <li><i class="fas fa-map-marker-alt"></i> Yaoundé, Cameroun</li>
                    </ul>
                </div>
            </div>
            <div class="copyright">
                &copy; 2025 ImmoLink - Tous droits réservés
            </div>
        </div>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Mobile menu toggle
            const mobileMenuBtn = document.getElementById('mobileMenuBtn');
            const navLinks = document.getElementById('navLinks');
            
            mobileMenuBtn.addEventListener('click', function() {
                navLinks.classList.toggle('active');
            });

            // Search tabs
            const searchTabs = document.querySelectorAll('.search-tab');
            searchTabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    searchTabs.forEach(t => t.classList.remove('active'));
                    this.classList.add('active');
                });
            });

            // Search form submission
            const searchForm = document.getElementById('searchForm');
            searchForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const location = document.getElementById('searchLocation').value;
                const type = document.getElementById('propertyType').value;
                const minPrice = document.getElementById('minPrice').value;
                const maxPrice = document.getElementById('maxPrice').value;
                const rooms = document.getElementById('rooms').value;
                
                // Build query string
                const params = new URLSearchParams();
                if (location) params.append('location', location);
                if (type) params.append('type', type);
                if (minPrice) params.append('min_price', minPrice);
                if (maxPrice) params.append('max_price', maxPrice);
                if (rooms) params.append('rooms', rooms);
                
                // Redirect to properties page with search parameters
                window.location.href = `properties.php?${params.toString()}`;
            });

            // Load featured properties (simulated)
            loadFeaturedProperties();
            
            // Animate statistics
            animateStatistics();
        });

        function loadFeaturedProperties() {
            // Simulated data - in real app, this would come from an API
            const properties = [
                {
                    id: 1,
                    title: "Appartement spacieux à Yaoundé",
                    price: "25 000 000 Fcfa",
                    address: "Tropicana, Yaoundé",
                    image: "https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?ixlib=rb-4.0.3&auto=format&fit=crop&w=870&q=80",
                    bedrooms: 3,
                    bathrooms: 2,
                    area: 85,
                    type: "vente"
                },
                {
                    id: 2,
                    title: "Maison moderne avec jardin",
                    price: "30 000 Fcfa/mois",
                    address: "Yassa, Douala",
                    image: "https://images.unsplash.com/photo-1574362848149-11496d93a7c7?ixlib=rb-4.0.3&auto=format&fit=crop&w=784&q=80",
                    bedrooms: 4,
                    bathrooms: 2,
                    area: 110,
                    type: "location"
                },
                {
                    id: 3,
                    title: "Duplex avec vue sur mer",
                    price: "42 500 000 Fcfa",
                    address: "Ngousso, Kribi",
                    image: "https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?ixlib=rb-4.0.3&auto=format&fit=crop&w=870&q=80",
                    bedrooms: 3,
                    bathrooms: 2,
                    area: 75,
                    type: "vente"
                }
            ];

            const propertiesGrid = document.getElementById('featuredProperties');
            propertiesGrid.innerHTML = properties.map(property => `
                <div class="property-card">
                    <span class="property-badge">${property.type === 'vente' ? 'À vendre' : 'À louer'}</span>
                    <img src="${property.image}" alt="${property.title}" class="property-image">
                    <div class="property-details">
                        <div class="property-price">${property.price}</div>
                        <h3 class="property-title">${property.title}</h3>
                        <div class="property-address">
                            <i class="fas fa-map-marker-alt"></i> ${property.address}
                        </div>
                        <div class="property-features">
                            <span><i class="fas fa-bed"></i> ${property.bedrooms} chambres</span>
                            <span><i class="fas fa-bath"></i> ${property.bathrooms} sdb</span>
                            <span><i class="fas fa-ruler-combined"></i> ${property.area} m²</span>
                        </div>
                        <div class="property-actions">
                            <a href="property-details.php?id=${property.id}" class="btn btn-primary" style="flex: 1;">Voir les détails</a>
                            <button class="btn btn-outline" onclick="addToFavorites(${property.id})">
                                <i class="far fa-heart"></i>
                            </button>
                        </div>
                    </div>
                </div>
            `).join('');
        }

        function animateStatistics() {
            const stats = {
                properties: 1250,
                users: 5430,
                transactions: 890,
                cities: 12
            };

            const duration = 2000; // ms
            const interval = 20; // ms
            const steps = duration / interval;

            Object.keys(stats).forEach(stat => {
                const element = document.getElementById(`stat${stat.charAt(0).toUpperCase() + stat.slice(1)}`);
                const target = stats[stat];
                let current = 0;
                const increment = target / steps;

                const timer = setInterval(() => {
                    current += increment;
                    if (current >= target) {
                        clearInterval(timer);
                        element.textContent = target.toLocaleString();
                    } else {
                        element.textContent = Math.floor(current).toLocaleString();
                    }
                }, interval);
            });
        }

        function addToFavorites(propertyId) {
            // In a real app, this would make an API call
            alert(`Le bien #${propertyId} a été ajouté à vos favoris !`);
        }
    </script>
</body>
</html>